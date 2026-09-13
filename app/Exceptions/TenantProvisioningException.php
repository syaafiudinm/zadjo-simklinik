<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TenantProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
        public readonly ?string $step,
        Throwable $previous,
    ) {
        $where = $step ? " pada langkah [{$step}]" : '';

        parent::__construct(
            "Provisioning tenant [{$slug}] gagal{$where}: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
