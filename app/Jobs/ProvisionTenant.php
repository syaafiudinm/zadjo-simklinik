<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Menjalankan provisioning tenant di worker antrian.
 *
 * Job ini berjalan di konteks PUSAT (belum ada tenant yang aktif saat ia
 * di-dispatch), jadi tidak butuh TenantAwareJob dari S1-08.
 */
class ProvisionTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Tidak di-retry. Kegagalan sudah membongkar tenant sampai bersih; retry
     * hanya akan menemukan baris yang sudah tidak ada. Membuat ulang adalah
     * keputusan operator, lewat `tenant:create` lagi.
     */
    public int $tries = 1;

    /** Target PRD FR-M23.1: tenant siap < 3 menit. */
    public int $timeout = 170;

    public function __construct(public readonly string $tenantId) {}

    public function handle(TenantProvisioner $provisioner): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $provisioner->provision($tenant);
    }

    /**
     * Dipanggil juga saat worker di-kill karena timeout — kondisi ketika blok
     * catch di dalam provisioner tidak pernah sempat berjalan.
     */
    public function failed(?Throwable $exception): void
    {
        if ($tenant = Tenant::find($this->tenantId)) {
            app(TenantProvisioner::class)->abandon($tenant);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['provisioning', 'tenant:'.$this->tenantId];
    }
}
