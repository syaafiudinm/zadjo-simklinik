<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Support\Tenancy\TenantDatabaseConfig;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Satu tenant = satu fasyankes, dengan satu database sendiri.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 * @property string|null $plan
 * @property string|null $db_host
 * @property int|null $db_port
 * @property string|null $db_name
 * @property string|null $db_username
 * @property string|null $db_password
 * @property \Carbon\Carbon|null $activated_at
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;
    use HasFactory;

    /**
     * `stancl/tenancy` membaca konfigurasi koneksi dari atribut berawalan
     * `internalPrefix() . 'db_'` dan memetakannya ke config koneksi dengan
     * prefix itu dilucuti (`db_host` -> `host`, `db_port` -> `port`, dst).
     *
     * Prefix bawaan `tenancy_` dikosongkan supaya nama kolom di database
     * terbaca apa adanya — `db_host`, bukan `tenancy_db_host`. Konsekuensinya:
     * setiap kolom baru yang diawali `db_` akan ikut masuk ke konfigurasi
     * koneksi PDO. Jangan menambah kolom berawalan `db_` untuk keperluan lain.
     */
    public static function internalPrefix(): string
    {
        return '';
    }

    /**
     * Kolom fisik. Atribut di luar daftar ini disimpan di kolom JSON `data`
     * oleh trait VirtualColumn bawaan paket.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'slug',
            'name',
            'status',
            'plan',
            'db_connection',
            'db_host',
            'db_port',
            'db_name',
            'db_username',
            'db_password',
            'activated_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'activated_at' => 'datetime',
            // Kredensial database tenant tidak pernah tersimpan plaintext.
            // Kuncinya APP_KEY, yang hidup di environment, bukan di database —
            // sehingga dump database saja tidak cukup untuk membukanya.
            'db_password' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            // Koordinat database diisi sejak tenant dibuat, walaupun untuk saat
            // ini selalu sama dengan server pusat. Kolom yang sudah terisi sejak
            // hari pertama inilah yang nanti membuat pemindahan tenant besar ke
            // server DB terpisah cuma soal UPDATE satu baris (PRD §5.4 poin 3).
            $central = config('tenancy.database.central_connection');

            $tenant->db_host ??= config("database.connections.{$central}.host");
            $tenant->db_port ??= (int) config("database.connections.{$central}.port");
        });
    }

    /**
     * Memakai DatabaseConfig kustom yang mengabaikan kolom `db_*` bernilai null.
     * Lihat App\Support\Tenancy\TenantDatabaseConfig untuk alasannya.
     */
    public function database(): TenantDatabaseConfig
    {
        return new TenantDatabaseConfig($this);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<TenantSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    /**
     * Nilai konfigurasi per klinik dari tabel `tenant_settings` (database pusat).
     *
     * Dimuat sekali per instance model; setiap request mendapat instance tenant
     * baru dari resolver, jadi tidak ada nilai basi lintas request.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $setting = $this->settings->firstWhere('key', $key);

        return $setting?->value ?? $default;
    }

    /** FR-M21.6 — menit idle sebelum logout otomatis, dijepit ke batas aman. */
    public function idleTimeoutMinutes(): int
    {
        [$min, $max] = config('simklinik.idle_timeout_bounds');

        $minutes = (int) $this->setting('session.idle_timeout_minutes', config('simklinik.idle_timeout_minutes'));

        return max($min, min($max, $minutes));
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isReadOnly(): bool
    {
        return $this->status === TenantStatus::ReadOnly;
    }

    /** Subdomain tempat tenant ini dilayani. */
    public function primaryDomain(): string
    {
        return $this->slug.'.'.config('tenancy.primary_central_domain');
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
