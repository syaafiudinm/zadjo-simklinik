<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| S1-05 — Autentikasi tenant-scoped
|--------------------------------------------------------------------------
*/

/**
 * Login lewat form sungguhan dan kembalikan nilai cookie sesinya, supaya
 * request berikutnya bisa dikirim sebagai "browser" yang sama.
 */
it('menampilkan halaman login di subdomain klinik', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->get($this->tenantUrl($tenant, '/login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->where('tenant.name', 'Klinik klinik-melati'));
});

it('memasukkan pengguna dengan kredensial yang benar dan mencatat waktu masuknya', function () {
    $tenant = $this->createTenant('klinik-melati');
    setPassword($tenant, 'admin@klinik-melati.test', PASSWORD_A);

    $this->post($this->tenantUrl($tenant, '/login'), ['email' => 'admin@klinik-melati.test', 'password' => PASSWORD_A])
        ->assertRedirect($this->tenantUrl($tenant, '/'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect($this->adminOf($tenant)->last_login_at)->not->toBeNull();
});

it('memperlakukan email yang sama di dua klinik sebagai dua akun terpisah', function () {
    // "Walaupun email dan password sama" (kriteria S1-05) punya satu makna yang
    // benar: login di B hanya pernah mengautentikasi akun milik B. Password
    // akun A tidak berlaku di B, dan akun yang dimasukkan adalah baris B.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $this->createTenantUser($a, 'practitioner', ['email' => 'dr.aditya@contoh.test', 'name' => 'Aditya di A', 'password' => PASSWORD_A]);
    $this->createTenantUser($b, 'nurse', ['email' => 'dr.aditya@contoh.test', 'name' => 'Aditya di B', 'password' => PASSWORD_B]);

    $this->post($this->tenantUrl($b, '/login'), ['email' => 'dr.aditya@contoh.test', 'password' => PASSWORD_A])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->freshProcess();
    $this->post($this->tenantUrl($b, '/login'), ['email' => 'dr.aditya@contoh.test', 'password' => PASSWORD_B])
        ->assertRedirect($this->tenantUrl($b, '/'))
        ->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    expect(auth()->user()->name)->toBe('Aditya di B')
        ->and(auth()->user()->hasRole('nurse'))->toBeTrue();
});

it('mengeluarkan pengguna setelah idle melewati batas bawaan', function () {
    $tenant = $this->createTenant('klinik-melati');
    setPassword($tenant, 'admin@klinik-melati.test', PASSWORD_A);
    $cookie = loginVia($this, $tenant, 'admin@klinik-melati.test', PASSWORD_A);

    $this->travel(14)->minutes();
    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))->assertOk();

    // Hitungan dimulai ulang dari request terakhir, bukan dari waktu login.
    $this->travel(14)->minutes();
    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))->assertOk();

    $this->travel(16)->minutes();
    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))
        ->assertRedirect($this->tenantUrl($tenant, '/login'))
        ->assertSessionHas('status', fn ($status) => str_contains($status, '15 menit'));

    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))
        ->assertRedirect($this->tenantUrl($tenant, '/login'));
});

it('memakai batas idle yang diatur per klinik', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    TenantSetting::create(['tenant_id' => $a->id, 'key' => 'session.idle_timeout_minutes', 'value' => 30]);
    setPassword($a, 'admin@klinik-a.test', PASSWORD_A);
    setPassword($b, 'admin@klinik-b.test', PASSWORD_B);

    $cookieA = loginVia($this, $a, 'admin@klinik-a.test', PASSWORD_A);
    $this->freshProcess();
    $cookieB = loginVia($this, $b, 'admin@klinik-b.test', PASSWORD_B);

    $this->travel(20)->minutes();

    asBrowser($this, $cookieA)->get($this->tenantUrl($a, '/'))->assertOk();
    asBrowser($this, $cookieB)->get($this->tenantUrl($b, '/'))->assertRedirect($this->tenantUrl($b, '/login'));
});

it('menjepit batas idle klinik ke rentang yang aman', function () {
    $tenant = $this->createTenant('klinik-a');

    TenantSetting::create(['tenant_id' => $tenant->id, 'key' => 'session.idle_timeout_minutes', 'value' => 100000]);
    expect($tenant->fresh()->idleTimeoutMinutes())->toBe(120);

    TenantSetting::where('tenant_id', $tenant->id)->update(['value' => json_encode(0)]);
    expect($tenant->fresh()->idleTimeoutMinutes())->toBe(5);
});

it('mengirim tautan reset password yang menunjuk subdomain klinik tanpa membocorkan email terdaftar', function () {
    Notification::fake();

    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $this->createTenantUser($b, 'registrar', ['email' => 'rina@contoh.test']);

    $known = $this->post($this->tenantUrl($b, '/forgot-password'), ['email' => 'rina@contoh.test']);
    $this->freshProcess();
    $unknown = $this->post($this->tenantUrl($a, '/forgot-password'), ['email' => 'rina@contoh.test']);

    // Jawaban identik untuk email terdaftar dan tidak terdaftar.
    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));

    // Dua admin/registrar ber-id sama di dua database, jadi pencocokan
    // penerima lewat id model tidak bermakna di sini. Diperiksa langsung:
    // tepat satu notifikasi reset terkirim, dan tautannya menunjuk klinik B.
    // Struktur: [kelas notifiable => [id => [kelas notifikasi => [kiriman...]]]]
    $sent = collect(Notification::sentNotifications()[User::class] ?? [])
        ->flatMap(fn (array $byClass) => $byClass[ResetPasswordNotification::class] ?? []);

    expect($sent)->toHaveCount(1);

    $url = $b->run(fn () => $sent->first()['notification']->toMail(User::where('email', 'rina@contoh.test')->first())->actionUrl);

    expect($url)->toStartWith($this->tenantUrl($b, '/reset-password/'));
});

it('mengatur ulang password hanya untuk akun di klinik tempat token diterbitkan', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $this->createTenantUser($a, 'registrar', ['email' => 'rina@contoh.test', 'password' => PASSWORD_A]);
    $this->createTenantUser($b, 'registrar', ['email' => 'rina@contoh.test', 'password' => PASSWORD_B]);

    $token = $b->run(fn () => Password::broker('users')->createToken(User::where('email', 'rina@contoh.test')->first()));

    // Token B tidak berlaku di A.
    $this->post($this->tenantUrl($a, '/reset-password'), [
        'token' => $token, 'email' => 'rina@contoh.test',
        'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123',
    ])->assertSessionHasErrors('email');

    $this->freshProcess();
    $this->post($this->tenantUrl($b, '/reset-password'), [
        'token' => $token, 'email' => 'rina@contoh.test',
        'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123',
    ])->assertRedirect($this->tenantUrl($b, '/login'));

    $b->run(fn () => expect(Hash::check('PasswordBaru123', User::where('email', 'rina@contoh.test')->first()->password))->toBeTrue());
    $a->run(fn () => expect(Hash::check(PASSWORD_A, User::where('email', 'rina@contoh.test')->first()->password))->toBeTrue());
});

it('menerima undangan, mengaktifkan akun, dan langsung memasukkan pemiliknya', function () {
    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);
    $token = $tenant->run(fn () => Password::broker('invitations')->createToken($admin));

    $this->post($this->tenantUrl($tenant, '/invitation'), [
        'token' => $token, 'email' => $admin->email,
        'password' => 'AdminMelati123', 'password_confirmation' => 'AdminMelati123',
    ])->assertRedirect($this->tenantUrl($tenant, '/'));

    $this->assertAuthenticated();
    $fresh = $this->adminOf($tenant);
    expect($fresh->activated_at)->not->toBeNull()
        ->and($fresh->email_verified_at)->not->toBeNull();
});

it('tidak menukar token undangan dengan token reset password', function () {
    // Token reset berlaku 60 menit, undangan 72 jam. Kalau keduanya bisa
    // saling dipakai, token reset yang bocor hidup 72 kali lebih lama.
    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);
    [$reset, $invite] = $tenant->run(fn () => [
        Password::broker('users')->createToken($admin),
        Password::broker('invitations')->createToken($admin),
    ]);
    $payload = ['email' => $admin->email, 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'];

    $this->post($this->tenantUrl($tenant, '/invitation'), $payload + ['token' => $reset])->assertSessionHasErrors('email');
    $this->freshProcess();
    $this->post($this->tenantUrl($tenant, '/reset-password'), $payload + ['token' => $invite])->assertSessionHasErrors('email');
});

it('tetap mengizinkan login di klinik hanya-baca', function () {
    // FR-M23.4 — memblokir login sama saja memblokir akses baca rekam medis.
    $tenant = $this->createTenant('klinik-anggrek', ['status' => TenantStatus::ReadOnly]);
    setPassword($tenant, 'admin@klinik-anggrek.test', PASSWORD_A);

    $cookie = loginVia($this, $tenant, 'admin@klinik-anggrek.test', PASSWORD_A);

    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))->assertOk();
    asBrowser($this, $cookie)->post($this->tenantUrl($tenant, '/logout'))->assertRedirect($this->tenantUrl($tenant, '/login'));
});

it('mengeluarkan pengguna dan membatalkan sesinya', function () {
    $tenant = $this->createTenant('klinik-melati');
    setPassword($tenant, 'admin@klinik-melati.test', PASSWORD_A);
    $cookie = loginVia($this, $tenant, 'admin@klinik-melati.test', PASSWORD_A);

    asBrowser($this, $cookie)->post($this->tenantUrl($tenant, '/logout'))
        ->assertRedirect($this->tenantUrl($tenant, '/login'));

    // Cookie lama tidak lagi membuka apa pun.
    asBrowser($this, $cookie)->get($this->tenantUrl($tenant, '/'))
        ->assertRedirect($this->tenantUrl($tenant, '/login'));
});
