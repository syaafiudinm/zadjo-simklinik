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
 * Bedanya dengan manager bawaan paket:
 *
 * 1. User dibuat TANPA grant level database. Hak per tabel diberikan oleh
 *    TenantDatabaseGrants setelah migrasi — lihat kelas itu untuk alasannya.
 *
 * 2. Penghapusan dibuat idempoten. Bawaan
 * menjalankan `DROP DATABASE` (tanpa IF EXISTS) lalu baru `DROP USER`; kalau
 * databasenya sudah tidak ada, query pertama melempar error dan user MySQL
 * yatim tertinggal selamanya. Itu persis kondisi yang terjadi saat rollback
 * provisioning yang gagal di tengah jalan.
 */
class TenantDatabaseManager extends PermissionControlledMySQLDatabaseManager
{
    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        $username = $databaseConfig->getUsername();
        $password = $databaseConfig->getPassword();

        // Password dijamin alfanumerik oleh generator di TenancyServiceProvider;
        // statement ini tidak mendukung parameter binding.
        return $this->database()->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY '{$password}'");
    }

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
