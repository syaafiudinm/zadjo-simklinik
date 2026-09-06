<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder database PUSAT.
 *
 * Untuk isi database tenant, lihat TenantDatabaseSeeder — dijalankan per tenant
 * oleh `php artisan tenants:seed`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantSeeder::class);
    }
}
