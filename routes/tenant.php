<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\Auth\AcceptInvitationController;
use App\Http\Controllers\Tenant\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Tenant\Auth\NewPasswordController;
use App\Http\Controllers\Tenant\Auth\PasswordResetLinkController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureTenantIsUsable;
use App\Http\Middleware\ScopeSessionToTenant;
use App\Models\User;
use App\Rules\TenantSlug;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

/*
|--------------------------------------------------------------------------
| Rute Tenant — aplikasi klinik
|--------------------------------------------------------------------------
|
| Dilayani dari `{tenant}.<domain-pusat>`. Batasan domain di sini bukan
| kosmetik: karena rute tenant dan rute pusat dibedakan saat PENCOCOKAN rute,
| bukan di dalam controller, sebuah path yang lupa dipasangi middleware tetap
| tidak bisa bocor ke sisi yang salah.
|
| Seluruh autentikasi hidup di sini, bukan di rute pusat: tabel `users` ada
| di database tenant, jadi login hanya bermakna setelah tenant teridentifikasi.
|
| Modul klinis (pendaftaran, antrian, encounter) menyusul di Sprint 2.
|
*/

$centralDomain = config('tenancy.primary_central_domain');

// Subdomain yang dipesan untuk aplikasi pusat dikeluarkan lewat negative
// lookahead, supaya `admin.simklinik.id` tidak pernah dianggap tenant bernama
// "admin" dan jatuh ke rute pusat sebagaimana mestinya.
//
// Lookahead-nya diakhiri titik, bukan `$`. Pola parameter ini ditanam di
// tengah regex host, jadi `$` berarti akhir seluruh hostname — `(?!admin$)`
// akan selalu lolos untuk `admin.simklinik.id` dan penjagaannya tidak berbunyi.
$reserved = implode('|', array_map(
    'preg_quote',
    (array) config('tenancy.reserved_subdomains')
));

Route::domain('{tenant}.'.$centralDomain)
    // Pola slug diambil dari aturan validasinya, supaya tidak pernah ada slug
    // yang lolos validasi tapi tidak cocok dengan rute.
    ->where(['tenant' => "(?!(?:{$reserved})\\.)".TenantSlug::PATTERN])
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        // Mengikat sesi ke tenant yang menerbitkannya. Cookie sesi sudah
        // terikat host, tapi ini menutup kasus cookie yang dipindah tangan.
        ScopeSessionToTenant::class,
        EnsureTenantIsUsable::class,
    ])
    ->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

            Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
            Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware('throttle:tenant-password-email')
                ->name('password.email');

            Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
            Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

            Route::get('/invitation/{token}', [AcceptInvitationController::class, 'create'])->name('invitation.accept');
            Route::post('/invitation', [AcceptInvitationController::class, 'store'])->name('invitation.store');
        });

        Route::middleware(['auth', EnforceIdleTimeout::class])->group(function () {
            Route::get('/', function () {
                // Identitas tenant sudah dibagikan ke setiap halaman lewat
                // HandleInertiaRequests::share(). Mengirimkannya lagi di sini akan
                // MENIMPA prop bersama itu — termasuk penanda `readOnly` yang
                // dipakai banner peringatan — dan bannernya diam-diam hilang.
                return Inertia::render('Tenant/Dashboard', [
                    // Dibaca dari database TENANT. Kalau angka ini pernah sama
                    // dengan angka tenant lain, isolasi database sedang bocor.
                    'jumlahPengguna' => User::count(),
                ]);
            })->name('tenant.dashboard');

            // Dipanggil frontend selama pengguna aktif mengetik tanpa berpindah
            // halaman, supaya form anamnesis yang panjang tidak berujung logout
            // saat disimpan. Middleware EnforceIdleTimeout yang memperbarui cap waktunya.
            Route::get('/session/heartbeat', fn () => response()->noContent())->name('session.heartbeat');

            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

            // Middleware `permission:` di rute DAN Gate di controller. Rute
            // menolak lebih awal; policy tetap berlaku kalau controller yang
            // sama kelak dipanggil dari rute lain yang lupa dipasangi middleware.
            Route::get('/users', [UserController::class, 'index'])
                ->middleware('permission:user.view')
                ->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])
                ->middleware('permission:user.create')
                ->name('users.create');
            Route::post('/users', [UserController::class, 'store'])
                ->middleware('permission:user.create')
                ->name('users.store');

            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:audit_log.view')
                ->name('audit-logs.index');
        });
    });
