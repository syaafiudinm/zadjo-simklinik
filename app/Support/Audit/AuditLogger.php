<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogicException;

/**
 * Satu-satunya jalan menulis jejak audit.
 */
final class AuditLogger
{
    public const MASK = '[disamarkan]';

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditEvent $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        array $context = [],
        ?Authenticatable $actor = null,
    ): AuditLog {
        if (! tenancy()->initialized) {
            // Tabel audit hidup di database tenant. Tanpa tenancy, koneksi
            // default adalah database pusat dan insert akan gagal — atau lebih
            // buruk, di masa depan mendarat di tempat yang salah.
            throw new LogicException("Kejadian audit [{$event->value}] dicatat di luar konteks tenant.");
        }

        $actor ??= $this->currentActor();
        $channel = $this->channel();

        return AuditLog::create([
            'occurred_at' => now(),
            'event' => $event,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_label' => $actor instanceof User ? "{$actor->name} <{$actor->email}>" : ($actor ? (string) $actor->getAuthIdentifier() : null),
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'auditable_label' => $subject ? $this->label($subject) : null,
            'old_values' => $old === [] ? null : $this->sanitize($old),
            'new_values' => $new === [] ? null : $this->sanitize($new),
            'reason' => $reason,
            'channel' => $channel,
            'request_id' => Context::get('request_id'),
            'ip_address' => $channel === 'http' ? request()->ip() : null,
            'user_agent' => $channel === 'http' ? Str::limit((string) request()->userAgent(), 509) : null,
            'context' => $context === [] ? null : $this->sanitize($context),
        ]);
    }

    /**
     * FR-M22.1 — mencatat akses baca atas satu rekam medis tertentu.
     *
     * Dipanggil per rekam medis yang DIBUKA, bukan per baris yang tampil di
     * daftar. Akses berulang oleh orang yang sama dalam jendela singkat
     * dicatat sekali; fakta aksesnya tetap tercatat, lalu lintas refresh dan
     * prefetch tidak.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordAccess(Model $record, array $context = []): ?AuditLog
    {
        $actor = $this->currentActor();
        $window = (int) config('simklinik.audit.access_dedup_seconds');

        if ($window > 0) {
            $key = implode(':', [
                'audit-access', tenant()->getTenantKey(), $actor?->getAuthIdentifier() ?? 'anon',
                $record->getMorphClass(), $record->getKey(),
            ]);

            // Cap waktu dibandingkan dengan now(), bukan mengandalkan TTL Redis
            // semata: jam aplikasi yang bisa digeser di test harus menentukan.
            // Tidak atomik — dua request yang benar-benar bersamaan bisa
            // menulis dua baris. Untuk jejak audit, kelebihan catat adalah
            // arah kesalahan yang aman.
            $last = Cache::get($key);
            $now = now()->getTimestamp();

            // Store Redis mengembalikan nilai numerik sebagai string.
            if (is_numeric($last) && $now - (int) $last < $window) {
                return null;
            }

            Cache::put($key, $now, $window);
        }

        return $this->record(AuditEvent::Viewed, $record, context: $context, actor: $actor);
    }

    /**
     * Tidak memicu resolusi user dari sesi. Di worker antrian dan perintah
     * artisan tidak ada sesi yang bermakna; menyentuhnya hanya membaca store
     * sesi tanpa alasan.
     */
    private function currentActor(): ?Authenticatable
    {
        $guard = Auth::guard('web');

        return $guard->hasUser() ? $guard->user() : null;
    }

    private function channel(): string
    {
        if ($channel = Context::getHidden('audit_channel')) {
            return $channel;
        }

        return request()->route() !== null ? 'http' : 'console';
    }

    private function label(Model $subject): string
    {
        return method_exists($subject, 'auditLabel')
            ? $subject->auditLabel()
            : class_basename($subject).' #'.$subject->getKey();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $masked = (array) config('simklinik.audit.masked_attributes');

        foreach ($values as $key => $value) {
            if (in_array($key, $masked, true)) {
                $values[$key] = self::MASK;

                continue;
            }

            $values[$key] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof BackedEnum => $value->value,
                is_array($value) => $this->sanitize($value),
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                default => $value,
            };
        }

        return $values;
    }
}
