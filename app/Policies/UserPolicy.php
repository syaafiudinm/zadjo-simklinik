<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Kerangka policy untuk pengguna klinik.
 *
 * Policy — bukan hanya middleware `permission:` di rute — karena aturan
 * berbasis objek menyusul: menonaktifkan akun sendiri, mengubah role diri
 * sendiri (FR-M21.7), dan nanti aturan "dokter hanya melihat pasien yang
 * pernah ia layani" (FR-M21.4) mengikuti pola yang sama untuk model pasien.
 *
 * Tidak ada Gate::before yang meloloskan admin untuk semua hal. Admin klinik
 * mendapat persis permission yang tertulis di PermissionCatalog.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('user.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('user.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('user.update');
    }

    public function deactivate(User $actor, User $target): bool
    {
        // Mengunci diri sendiri keluar dari klinik tidak pernah disengaja.
        return $actor->can('user.deactivate') && ! $actor->is($target);
    }
}
