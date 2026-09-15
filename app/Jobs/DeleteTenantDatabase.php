<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;

/**
 * Menghapus database dan user MySQL tenant saat model Tenant dihapus.
 *
 * Penghapusannya sendiri idempoten (lihat App\Support\Tenancy\TenantDatabaseManager).
 * Job ini hanya menambahkan satu aturan: tenant yang ditandai
 * `create_database => false` memang tidak pernah punya database, jadi tidak
 * ada yang perlu disentuh. Konvensi flag itu diambil dari paket —
 * `Jobs\CreateDatabase` memakai flag yang sama untuk melewatkan pembuatan.
 */
class DeleteTenantDatabase implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        if ($this->tenant->getInternal('create_database') === false) {
            return;
        }

        (new DeleteDatabase($this->tenant))->handle();
    }
}
