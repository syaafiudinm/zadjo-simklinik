<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dipancarkan setiap satu langkah provisioning selesai. Dipakai untuk log
 * progres, dan oleh test untuk menyuntikkan kegagalan di langkah tertentu.
 */
class TenantProvisioningStepCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $step,
    ) {}
}
