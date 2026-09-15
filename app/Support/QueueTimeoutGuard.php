<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\ProvisionTenant;
use RuntimeException;

/**
 * Invarian antrian: `retry_after` Redis harus lebih besar dari timeout job dan
 * supervisor Horizon terlama.
 *
 * Kalau tidak, Redis menganggap job yang masih berjalan sudah mati dan
 * menyerahkannya ke worker kedua. Untuk ProvisionTenant itu berarti dua
 * provisioning bersamaan untuk satu klinik; untuk sinkronisasi SATUSEHAT
 * (Sprint 2), pengiriman ganda.
 */
final class QueueTimeoutGuard
{
    /** @var list<string> */
    public const WORKER_COMMANDS = ['horizon', 'horizon:supervisor', 'horizon:work', 'horizon:listen', 'queue:work', 'queue:listen'];

    public static function longestTimeout(): int
    {
        return (int) collect([(new ProvisionTenant('guard'))->timeout])
            ->merge(collect(config('horizon.defaults'))->pluck('timeout'))
            ->merge(collect(config('horizon.environments'))->flatMap(fn ($supervisors) => collect($supervisors)->pluck('timeout')))
            ->filter()
            ->max();
    }

    public static function assertSafe(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $longest = self::longestTimeout();

        if ($retryAfter <= $longest) {
            throw new RuntimeException(
                "REDIS_QUEUE_RETRY_AFTER ({$retryAfter} detik) harus lebih besar dari timeout job terlama ({$longest} detik). "
                .'Worker tidak dijalankan supaya job yang masih berjalan tidak diserahkan ke worker kedua.'
            );
        }
    }
}
