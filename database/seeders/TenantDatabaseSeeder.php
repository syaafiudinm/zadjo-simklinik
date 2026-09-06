<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder yang dijalankan DI DALAM database tenant.
 *
 * Dipanggil per tenant oleh `php artisan tenants:seed`. Role, permission, dan
 * poli default menyusul di S1-04/S1-06; untuk sekarang cukup satu akun admin
 * supaya subdomain tenant bisa dibuka dan diperiksa.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = tenant();

        User::firstOrCreate(
            ['email' => 'admin@'.($tenant?->slug ?? 'tenant').'.test'],
            [
                'name' => 'Admin '.($tenant?->name ?? 'Klinik'),
                'password' => Hash::make('password'),
            ]
        );
    }
}
