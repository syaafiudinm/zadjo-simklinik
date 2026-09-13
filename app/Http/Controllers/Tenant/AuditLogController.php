<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman sederhana untuk membaca jejak audit (S1-07).
 *
 * Laporan audit siap cetak untuk inspeksi Dinkes (FR-M22.9) dan ekspornya
 * menyusul. Halaman ini sengaja tidak menulis jejak audit untuk dirinya
 * sendiri: setiap perpindahan halaman dan filter akan menambah baris yang
 * tidak menjawab pertanyaan audit apa pun.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'actor' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor_id', $actor))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['q'] ?? null, function ($query, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($inner) => $inner
                    ->where('actor_label', 'like', $like)
                    ->orWhere('auditable_label', 'like', $like));
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurredAt' => $log->occurred_at->toIso8601String(),
                'event' => $log->event->value,
                'eventLabel' => $log->event->label(),
                'securitySignal' => $log->event->isSecuritySignal(),
                'actor' => $log->actor_label,
                'subjectType' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'subject' => $log->auditable_label,
                'oldValues' => $log->old_values,
                'newValues' => $log->new_values,
                'reason' => $log->reason,
                'context' => $log->context,
                'channel' => $log->channel,
                'ipAddress' => $log->ip_address,
            ]);

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filters' => array_merge(['event' => null, 'actor' => null, 'from' => null, 'to' => null, 'q' => null], $filters),
            'events' => collect(AuditEvent::cases())
                ->map(fn (AuditEvent $event) => ['value' => $event->value, 'label' => $event->label()])
                ->values(),
        ]);
    }
}
