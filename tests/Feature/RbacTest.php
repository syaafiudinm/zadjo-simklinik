<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ForbiddenMessage;
use App\Support\Rbac\PermissionCatalog;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| S1-06 — Role & permission
|--------------------------------------------------------------------------
*/

it('menyemai delapan role bawaan dengan permission persis sesuai katalog', function () {
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        expect(Permission::count())->toBe(count(PermissionCatalog::permissions()));

        foreach (PermissionCatalog::roles() as $name) {
            $actual = Role::findByName($name)->permissions->pluck('name')->sort()->values()->all();

            expect($actual)->toBe(PermissionCatalog::permissionsForRole($name), "Permission role {$name} menyimpang dari katalog.");
        }
    });
});

it('aman dijalankan ulang tanpa menyentuh role kustom klinik', function () {
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        Role::create(['name' => 'koordinator_shift'])->givePermissionTo('queue.view');
    });

    $this->artisan('tenants:seed', ['--tenants' => [$tenant->id], '--force' => true])->assertSuccessful();

    $tenant->run(function () {
        expect(Role::findByName('koordinator_shift')->permissions->pluck('name')->all())->toBe(['queue.view'])
            ->and(Permission::count())->toBe(count(PermissionCatalog::permissions()));
    });
});

it('menolak akses tanpa permission dengan halaman 403, dan menyembunyikan menunya', function () {
    // Skrip demo sprint langkah 4.
    $tenant = $this->createTenant('klinik-melati');
    $registrar = $this->createTenantUser($tenant, 'registrar');

    $this->actingAs($registrar)
        ->get($this->tenantUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('patient.create')
                && ! collect($permissions)->contains('user.view')));

    foreach (['/users', '/users/create'] as $path) {
        $this->get($this->tenantUrl($tenant, $path))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Errors/Forbidden')
                ->where('message', ForbiddenMessage::DEFAULT));
    }

    $this->post($this->tenantUrl($tenant, '/users'), [
        'name' => 'Penyusup', 'email' => 'x@x.test', 'role' => 'clinic_admin',
        'password' => 'Password12345', 'password_confirmation' => 'Password12345',
    ])->assertForbidden();

    $tenant->run(fn () => expect(User::where('email', 'x@x.test')->exists())->toBeFalse());
});

it('mengizinkan admin klinik membuat pengguna berperan petugas pendaftaran', function () {
    // Skrip demo sprint langkah 3.
    $tenant = $this->createTenant('klinik-melati');

    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant, '/users/create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Users/Create')
            ->where('roles', fn ($roles) => collect($roles)->pluck('value')->contains('registrar')));

    $this->post($this->tenantUrl($tenant, '/users'), [
        'name' => 'Rina', 'email' => 'rina@melati.test', 'role' => 'registrar',
        'password' => 'RinaMelati123', 'password_confirmation' => 'RinaMelati123',
    ])->assertRedirect($this->tenantUrl($tenant, '/users'));

    $tenant->run(function () {
        $rina = User::where('email', 'rina@melati.test')->firstOrFail();

        expect($rina->getRoleNames()->all())->toBe(['registrar'])
            ->and($rina->activated_at)->not->toBeNull();
    });

    $this->get($this->tenantUrl($tenant, '/users'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Users/Index')
            ->where('users', fn ($users) => collect($users)->pluck('email')->contains('rina@melati.test')));
});

it('mengizinkan admin klinik memberikan setiap peran bawaan', function () {
    // Regresi yang pernah terjadi: aturan anti-eskalasi menganggap break-glass
    // sebagai kuasa administratif, sehingga peran Dokter hilang dari pilihan
    // dan admin klinik tidak bisa membuat akun dokter.
    $tenant = $this->createTenant('klinik-melati');

    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant, '/users/create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->pluck('value')->sort()->values()->all()
                === collect(PermissionCatalog::roles())->sort()->values()->all()));
});

it('mencegah pemberian peran yang lebih berkuasa dari pemberinya', function () {
    // Tanpa aturan ini, role kustom mana pun yang punya `user.create` bisa
    // membuat akun `clinic_admin` lalu masuk dengan akun itu.
    $tenant = $this->createTenant('klinik-melati');

    $coordinator = $tenant->run(function () {
        Role::create(['name' => 'koordinator_pendaftaran'])->givePermissionTo(['user.view', 'user.create', 'queue.view']);

        return tap(User::factory()->create())->assignRole('koordinator_pendaftaran');
    });

    $this->actingAs($coordinator)
        ->get($this->tenantUrl($tenant, '/users/create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('roles', fn ($roles) => ! collect($roles)->pluck('value')->contains('clinic_admin')
            && collect($roles)->pluck('value')->contains('registrar')));

    $payload = ['name' => 'X', 'password' => 'Password12345', 'password_confirmation' => 'Password12345'];

    $this->post($this->tenantUrl($tenant, '/users'), $payload + ['email' => 'calon.admin@x.test', 'role' => 'clinic_admin'])
        ->assertSessionHasErrors(['role' => 'Anda tidak dapat memberikan peran ini.']);

    $this->post($this->tenantUrl($tenant, '/users'), $payload + ['email' => 'petugas@x.test', 'role' => 'registrar'])
        ->assertRedirect($this->tenantUrl($tenant, '/users'));

    $tenant->run(fn () => expect(User::where('email', 'calon.admin@x.test')->exists())->toBeFalse()
        ->and(User::where('email', 'petugas@x.test')->exists())->toBeTrue());
});

it('tidak memberi admin klinik akses rekam medis secara bawaan', function () {
    // Keputusan desain di PermissionCatalog: mengelola klinik tidak sama dengan
    // berhak membaca diagnosis pasien (minimisasi akses, UU PDP).
    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);

    $tenant->run(fn () => expect($admin->can('patient.view'))->toBeFalse()
        ->and($admin->can('encounter.view'))->toBeFalse()
        ->and($admin->can('user.create'))->toBeTrue()
        ->and($admin->can('audit_log.view'))->toBeTrue());
});

it('menolak email ganda di dalam klinik yang sama', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->actingAs($this->adminOf($tenant))
        ->post($this->tenantUrl($tenant, '/users'), [
            'name' => 'Admin Kedua', 'email' => 'admin@klinik-melati.test', 'role' => 'registrar',
            'password' => 'Password12345', 'password_confirmation' => 'Password12345',
        ])->assertSessionHasErrors(['email' => 'Email ini sudah dipakai pengguna lain di klinik ini.']);
});
