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
 * Menghapus database tenant, tapi hanya kalau memang pernah dibuat.
 *
 * `Jobs\DeleteDatabase` bawaan paket langsung menjalankan `DROP DATABASE` dan
 * gagal dengan error 1008 kalau databasenya tidak ada. Itu justru kondisi yang
 * paling mungkin terjadi saat kita paling membutuhkan penghapusan berjalan
 * mulus: rollback provisioning yang gagal SEBELUM database sempat dibuat
 * (S1-04). Kegagalan di jalur rollback meninggalkan tenant setengah jadi —
 * persis yang ingin dicegah.
 *
 * Konvensi flag `create_database => false` diambil dari paket: `Jobs\CreateDatabase`
 * memakai flag yang sama untuk melewatkan pembuatan database.
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
