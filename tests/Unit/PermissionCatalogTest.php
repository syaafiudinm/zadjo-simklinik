<?php

declare(strict_types=1);

use App\Support\Rbac\PermissionCatalog;

it('menjabarkan setiap role bawaan tanpa pola yang tidak dikenal', function () {
    foreach (PermissionCatalog::roles() as $role) {
        $permissions = PermissionCatalog::permissionsForRole($role);

        expect($permissions)->not->toBeEmpty()
            ->and($permissions)->toBe(array_values(array_unique($permissions)))
            ->and(array_diff($permissions, PermissionCatalog::permissions()))->toBeEmpty();
    }
});

it('memuat delapan role bawaan dari PRD FR-M21.1', function () {
    expect(PermissionCatalog::roles())->toEqualCanonicalizing([
        'clinic_admin', 'registrar', 'nurse', 'practitioner',
        'pharmacist', 'lab_staff', 'cashier', 'medical_record_officer',
    ]);
});

it('memakai pola modul.aksi untuk semua permission', function () {
    foreach (PermissionCatalog::permissions() as $permission) {
        expect($permission)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});

it('hanya menandai modul yang benar-benar ada sebagai modul administratif', function () {
    foreach (PermissionCatalog::PRIVILEGED_MODULES as $module) {
        expect(PermissionCatalog::MODULES)->toHaveKey($module);
    }
});

it('menolak role yang tidak ada di katalog', function () {
    PermissionCatalog::permissionsForRole('superuser');
})->throws(InvalidArgumentException::class);
