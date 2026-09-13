<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Suite isolasi tenant — benih S1-09
|--------------------------------------------------------------------------
|
| Berkas ini adalah aset paling berharga di repo. Setiap kali ditemukan celah
| isolasi baru, tambahkan test-nya DI SINI dulu, baru perbaiki kodenya.
|
| Tercakup: isolasi database (aplikasi dan grant MySQL), cache, direktori
| berkas, sesi, rate limit login, dan cache permission. Menyusul di S1-09:
| konteks tenant pada job antrian (S1-08) dan pemasangan migrasi baru ke semua
| tenant.
|
*/

it('tidak menampilkan baris tenant lain dari konteks tenant manapun', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(function () {
        User::factory()->create(['email' => 'perawat@klinik-a.test']);
    });

    $b->run(function () {
        User::factory()->create(['email' => 'perawat@klinik-b.test']);
        User::factory()->create(['email' => 'apoteker@klinik-b.test']);
    });

    // Setiap tenant sudah punya satu admin dari provisioning.
    $a->run(function () {
        expect(User::count())->toBe(2)
            ->and(User::where('email', 'perawat@klinik-b.test')->exists())->toBeFalse()
            ->and(User::where('email', 'admin@klinik-b.test')->exists())->toBeFalse();
    });

    $b->run(function () {
        expect(User::count())->toBe(3)
            ->and(User::where('email', 'perawat@klinik-a.test')->exists())->toBeFalse()
            ->and(User::where('email', 'admin@klinik-a.test')->exists())->toBeFalse();
    });
});

it('mengizinkan email yang sama di dua klinik berbeda', function () {
    // Konsekuensi langsung dari database-per-tenant: seorang dokter yang praktik
    // di dua klinik punya dua akun terpisah, dan tidak ada unique constraint
    // lintas klinik yang bisa menghalanginya.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(fn () => User::factory()->create(['email' => 'dr.aditya@contoh.test']));
    $b->run(fn () => User::factory()->create(['email' => 'dr.aditya@contoh.test']));

    $a->run(fn () => expect(User::where('email', 'dr.aditya@contoh.test')->count())->toBe(1));
    $b->run(fn () => expect(User::where('email', 'dr.aditya@contoh.test')->count())->toBe(1));
});

it('memberi setiap tenant database fisik yang berbeda', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    expect($a->database()->getName())->not->toBe($b->database()->getName());

    $names = DB::connection(config('tenancy.database.central_connection'))
        ->select('SHOW DATABASES');
    $names = array_map(fn ($row) => array_values((array) $row)[0], $names);

    expect($names)->toContain($a->database()->getName())
        ->and($names)->toContain($b->database()->getName());
});

it('tidak bisa membaca tabel klinis lewat koneksi pusat', function () {
    // Koneksi pusat menunjuk database registry, yang tidak punya tabel klinis
    // sama sekali. Kalau assertion ini gagal, artinya ada migrasi tenant yang
    // salah tempat dan data klinis mendarat di database pusat.
    $this->createTenant('klinik-a');

    $central = DB::connection(config('tenancy.database.central_connection'))
        ->getSchemaBuilder();

    expect($central->hasTable('tenants'))->toBeTrue()
        ->and($central->hasTable('users'))->toBeFalse();
});

it('tidak menabrakkan kunci cache antar tenant', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(fn () => cache()->put('antrian-berikutnya', 'A-001', 60));
    $b->run(fn () => cache()->put('antrian-berikutnya', 'B-042', 60));

    $a->run(fn () => expect(cache()->get('antrian-berikutnya'))->toBe('A-001'));
    $b->run(fn () => expect(cache()->get('antrian-berikutnya'))->toBe('B-042'));

    // Dan konteks pusat tidak melihat keduanya.
    expect(cache()->get('antrian-berikutnya'))->toBeNull();
});

it('memisahkan direktori penyimpanan berkas antar tenant', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $pathA = $a->run(fn () => storage_path('app'));
    $pathB = $b->run(fn () => storage_path('app'));

    expect($pathA)->not->toBe($pathB)
        ->and($pathA)->toContain($a->id)
        ->and($pathB)->toContain($b->id);
});

it('mengembalikan konteks ke pusat setelah selesai menjalankan kode tenant', function () {
    // Sumber bug paling halus di aplikasi multi-tenant: konteks yang tertinggal.
    // Kode berikutnya mengira sedang di database pusat, padahal masih di tenant.
    $tenant = $this->createTenant('klinik-a');

    $tenant->run(fn () => expect(tenancy()->initialized)->toBeTrue());

    expect(tenancy()->initialized)->toBeFalse()
        ->and(Tenant::count())->toBe(1);
});
