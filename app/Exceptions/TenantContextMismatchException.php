<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class TenantContextMismatchException extends RuntimeException
{
    public function __construct(string $job, ?string $expected, ?string $actual)
    {
        parent::__construct(sprintf(
            'Job %s milik tenant [%s] dijalankan di konteks [%s]. Dihentikan sebelum menyentuh data apa pun.',
            $job,
            $expected ?? 'tidak diketahui',
            $actual ?? 'pusat',
        ));
    }
}
