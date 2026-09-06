<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTenantIsUsable;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\ScopeSessions;

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
    ->where(['tenant' => "(?!(?:{$reserved})\\.)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?"])
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        // Mengikat sesi ke tenant yang menerbitkannya. Cookie sesi sudah
        // terikat host, tapi ini menutup kasus cookie yang dipindah tangan.
        ScopeSessions::class,
        EnsureTenantIsUsable::class,
    ])
    ->group(function () {
        Route::get('/', function () {
            // Identitas tenant sudah dibagikan ke setiap halaman lewat
            // HandleInertiaRequests::share(). Mengirimkannya lagi di sini akan
            // MENIMPA prop bersama itu — termasuk penanda `readOnly` yang
            // dipakai banner peringatan — dan bannernya diam-diam hilang.
            return Inertia::render('Tenant/Dashboard', [
                // Dibaca dari database TENANT. Kalau angka ini pernah sama
                // dengan angka tenant lain, isolasi database sedang bocor.
                'jumlahPengguna' => App\Models\User::count(),
            ]);
        })->name('tenant.dashboard');
    });
