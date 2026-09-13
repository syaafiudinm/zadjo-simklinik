<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

/**
 * Setiap tenant mendapat database DAN user MySQL sendiri, dengan grant yang
 * hanya berlaku di database miliknya.
 *
 * Isolasi di level aplikasi (koneksi yang berganti per request) bisa salah
 * konfigurasi. Isolasi di level MySQL tidak: user `klinik_a` secara fisik
 * tidak punya hak membaca `simklinik_klinik_b`, apa pun yang dilakukan kode.
 *
 * Bedanya dengan manager bawaan paket: penghapusan dibuat idempoten. Bawaan
 * menjalankan `DROP DATABASE` (tanpa IF EXISTS) lalu baru `DROP USER`; kalau
 * databasenya sudah tidak ada, query pertama melempar error dan user MySQL
 * yatim tertinggal selamanya. Itu persis kondisi yang terjadi saat rollback
 * provisioning yang gagal di tengah jalan.
 */
class TenantDatabaseManager extends PermissionControlledMySQLDatabaseManager
{
    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();

        $this->database()->statement("DROP DATABASE IF EXISTS `{$name}`");

        return $this->deleteUser($tenant->database());
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        $username = $databaseConfig->getUsername();

        if ($username === null || $username === '') {
            return true;
        }

        return $this->database()->statement("DROP USER IF EXISTS `{$username}`@`%`");
    }
}
