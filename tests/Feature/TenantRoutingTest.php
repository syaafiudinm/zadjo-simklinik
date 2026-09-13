<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureTenantIsUsable;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

/*
|--------------------------------------------------------------------------
| S1-03 — Resolusi subdomain & pemisahan rute
|--------------------------------------------------------------------------
*/

it('melayani subdomain tenant dengan aplikasi klinik', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tenant/Dashboard')
            ->where('tenant.slug', 'klinik-melati'));
});

it('mengarahkan tamu ke halaman login di subdomain klinik yang sama', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->get($this->tenantUrl($tenant))
        ->assertRedirect($this->tenantUrl($tenant, '/login'));
});

it('melayani domain pusat dengan aplikasi vendor', function () {
    $this->get($this->centralUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Central/Home'));
});

it('menolak rute pusat dari subdomain tenant', function () {
    // Kriteria terima S1-03. Yang menegakkan ini adalah pencocokan rute
    // (rute tenant punya batasan domain, rute pusat dijaga middleware),
    // bukan pemeriksaan di dalam controller yang bisa terlupa.
    $tenant = $this->createTenant('klinik-melati');

    // `/rute-hanya-pusat` tidak ada di grup tenant, jadi kalau isolasi bocor
    // ia akan jatuh ke rute pusat dan menjawab 200.
    $this->get($this->tenantUrl($tenant, '/rute-hanya-pusat'))
        ->assertNotFound();
});

it('menolak rute tenant dari domain pusat', function () {
    $this->createTenant('klinik-melati');

    // Halaman pusat yang muncul di domain pusat adalah Central/Home,
    // bukan dasbor klinik mana pun.
    $this->get($this->centralUrl())
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Central/Home'));

    expect(tenancy()->initialized)->toBeFalse();
});

it('menjawab subdomain tak dikenal dengan halaman 404 yang menjelaskan', function () {
    // Bukan sekadar kerapian: stack trace di subdomain salah ketik membocorkan
    // struktur internal aplikasi ke siapa pun yang menebak nama klinik.
    $this->get($this->tenantUrl('klinik-yang-tidak-ada'))
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/TenantNotFound'));
});

it('memperlakukan subdomain yang dipesan sebagai domain pusat', function () {
    // `admin` ada di tenancy.reserved_subdomains dan di central_domains.
    $this->get('http://admin.simklinik.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Central/Home'));
});

it('menolak host yang bukan domain pusat maupun subdomain tenant', function () {
    $this->get('http://acak.example.com/')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/TenantNotFound'));
});

it('menampilkan halaman penjelasan untuk tenant yang ditangguhkan', function () {
    $tenant = $this->createTenant('klinik-kamboja', ['status' => TenantStatus::Suspended]);

    // Tamu tidak diarahkan ke login dulu: status tenant dievaluasi sebelum
    // autentikasi. Lihat urutan prioritas di TenancyServiceProvider.
    $this->get($this->tenantUrl($tenant))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/TenantSuspended'));

    // Pengguna yang sudah login pun tetap tertahan.
    $this->freshProcess();
    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant, '/users'))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/TenantSuspended'));
});

it('menampilkan halaman tunggu untuk tenant yang masih diprovisioning', function () {
    $tenant = $this->createTenant('klinik-baru', ['status' => TenantStatus::Provisioning]);

    $this->get($this->tenantUrl($tenant))
        ->assertStatus(503)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/TenantProvisioning'));
});

it('mengizinkan baca tapi memblokir tulis pada tenant hanya-baca', function () {
    // FR-M23.4: rekam medis tetap terbaca walau langganan bermasalah.
    Route::domain('{tenant}.'.config('tenancy.primary_central_domain'))
        ->middleware(['web', InitializeTenancyBySubdomain::class, EnsureTenantIsUsable::class])
        ->post('/uji-tulis', fn () => response('tersimpan'));

    $tenant = $this->createTenant('klinik-anggrek', ['status' => TenantStatus::ReadOnly]);

    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant))
        ->assertOk();

    $this->post($this->tenantUrl($tenant, '/uji-tulis'))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Errors/Forbidden')
            ->where('message', fn (string $message) => str_contains($message, 'hanya-baca')));
});

it('menandai mode hanya-baca lewat props bersama, bukan per halaman', function () {
    $tenant = $this->createTenant('klinik-anggrek', ['status' => TenantStatus::ReadOnly]);

    // Halaman login pun menerima penanda ini — tanpa satu baris pun di
    // controller login yang mengirimkannya.
    $this->get($this->tenantUrl($tenant, '/login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->where('tenant.readOnly', true));
});

it('tidak membocorkan parameter rute subdomain ke argumen controller', function () {
    // Rute tenant dicocokkan lewat pola `{tenant}.<domain>`, jadi Laravel akan
    // meneruskan fragmen subdomain sebagai argumen pertama setiap action kalau
    // parameternya tidak dibuang lebih dulu.
    Route::domain('{tenant}.'.config('tenancy.primary_central_domain'))
        ->middleware(['web', InitializeTenancyBySubdomain::class, EnsureTenantIsUsable::class])
        ->get('/uji-parameter', fn () => response(json_encode(func_get_args())));

    $tenant = $this->createTenant('klinik-melati');

    $this->get($this->tenantUrl($tenant, '/uji-parameter'))
        ->assertOk()
        ->assertSee('[]', false);
});
