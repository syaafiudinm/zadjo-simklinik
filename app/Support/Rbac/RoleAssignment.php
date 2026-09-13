<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Menentukan role mana yang boleh diberikan seorang pengguna kepada orang lain.
 *
 * Tanpa aturan ini, siapa pun yang punya `user.create` — misalnya role kustom
 * "Koordinator Pendaftaran" — bisa membuat akun baru berperan `clinic_admin`,
 * lalu masuk dengan akun itu. Permission `user.create` diam-diam menjadi
 * permission untuk segalanya.
 *
 * Aturannya: sebuah role boleh diberikan hanya jika setiap permission
 * ADMINISTRATIF di dalamnya (lihat PermissionCatalog::PRIVILEGED_MODULES) sudah
 * dimiliki pemberinya.
 */
final class RoleAssignment
{
    /** @return Collection<int, Role> */
    public static function assignableBy(User $actor): Collection
    {
        $held = $actor->getAllPermissions()->pluck('name')->all();

        return Role::query()
            ->where('guard_name', PermissionCatalog::GUARD)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role) => self::privilegedPermissions($role)->diff($held)->isEmpty())
            ->values();
    }

    public static function canAssign(User $actor, string $roleName): bool
    {
        return self::assignableBy($actor)->contains('name', $roleName);
    }

    /** @return Collection<int, string> */
    private static function privilegedPermissions(Role $role): Collection
    {
        return $role->permissions
            ->pluck('name')
            ->filter(fn (string $name) => in_array(strtok($name, '.'), PermissionCatalog::PRIVILEGED_MODULES, true))
            ->values();
    }
}
