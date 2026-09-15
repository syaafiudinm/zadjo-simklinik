<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Menyemai permission dan role bawaan ke database TENANT.
 *
 * Idempoten, sehingga aman dijalankan ulang ke semua tenant setiap kali
 * katalog berubah (`php artisan tenants:seed --class=RolesAndPermissionsSeeder`):
 * - permission baru ditambahkan, yang lama tidak dihapus;
 * - permission role BAWAAN disinkronkan ke katalog;
 * - role KUSTOM buatan klinik tidak disentuh sama sekali.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $existing = Permission::query()->pluck('name')->all();
        $missing = array_diff(PermissionCatalog::permissions(), $existing);

        // Satu INSERT untuk semua permission yang belum ada, bukan 92 kali
        // firstOrCreate. Provisioning dan setiap test tenant membayar ini.
        if ($missing !== []) {
            $now = now();

            Permission::query()->insert(array_map(fn (string $name) => [
                'name' => $name,
                'guard_name' => PermissionCatalog::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($missing)));

            $registrar->forgetCachedPermissions();
        }

        foreach (PermissionCatalog::roles() as $name) {
            Role::findOrCreate($name, PermissionCatalog::GUARD)
                ->syncPermissions(PermissionCatalog::permissionsForRole($name));
        }

        $registrar->forgetCachedPermissions();
    }
}
