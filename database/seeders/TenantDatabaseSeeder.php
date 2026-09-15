<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data awal yang dijalankan DI DALAM database setiap tenant baru.
 *
 * Tidak membuat akun apa pun: admin pertama dibuat oleh
 * App\Services\Tenancy\TenantProvisioner dengan email yang benar dan
 * undangan, bukan akun contoh berpassword tebakan.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PolyclinicSeeder::class,
        ]);
    }
}
