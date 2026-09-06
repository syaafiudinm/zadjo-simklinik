<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| S1-02 — Central schema & model tenant
|--------------------------------------------------------------------------
*/

it('menyimpan kredensial database tenant dalam bentuk terenkripsi', function () {
    $tenant = Tenant::factory()->withoutDatabase()->create([
        'db_username' => 'klinik_melati_user',
        'db_password' => 'rahasia-sekali',
    ]);

    // Nilai mentah di database, tanpa lewat cast Eloquent.
    $raw = DB::table('tenants')->where('id', $tenant->id)->value('db_password');

    expect($raw)->not->toBe('rahasia-sekali')
        ->and($raw)->not->toContain('rahasia')
        ->and($tenant->fresh()->db_password)->toBe('rahasia-sekali');
});

it('mengisi koordinat database sejak tenant dibuat, bukan saat dibutuhkan', function () {
    // PRD §5.4 poin 3: kolom ini yang nanti memungkinkan memindahkan tenant ke
    // server DB terpisah tanpa mengubah kode. Kalau dibiarkan null sampai
    // "nanti kalau perlu", nanti berarti backfill lintas 50 database.
    $tenant = Tenant::factory()->withoutDatabase()->create();

    $central = config('tenancy.database.central_connection');

    expect($tenant->db_host)->toBe(config("database.connections.{$central}.host"))
        ->and($tenant->db_port)->toBe((int) config("database.connections.{$central}.port"));
});

it('menamai database tenant dari slug, bukan dari UUID', function () {
    $tenant = Tenant::factory()->withoutDatabase()->create(['slug' => 'klinik-melati']);

    expect($tenant->database()->getName())
        ->toBe(config('tenancy.database.prefix').'klinik_melati'.config('tenancy.database.suffix'));
});

it('menolak dua tenant dengan slug yang sama', function () {
    Tenant::factory()->withoutDatabase()->create(['slug' => 'klinik-melati']);

    Tenant::factory()->withoutDatabase()->create(['slug' => 'klinik-melati']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

it('memakai UUID v7 sebagai kunci primer tenant', function () {
    $tenant = Tenant::factory()->withoutDatabase()->create();

    // Digit pertama oktet ketujuh menandai versi UUID.
    expect($tenant->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('mengabaikan kolom db_* yang masih kosong saat menyusun koneksi tenant', function () {
    // Sebelum S1-04 membuat user MySQL per tenant, db_username dan db_password
    // masih null. Kalau null itu ikut tersalin ke config koneksi, ia menimpa
    // kredensial koneksi template dan tenant gagal konek dengan pesan yang
    // menyesatkan ("Access denied for user ''").
    $tenant = Tenant::factory()->withoutDatabase()->create([
        'db_username' => null,
        'db_password' => null,
    ]);

    $connection = $tenant->database()->connection();

    expect($connection['username'])->toBe(config('database.connections.mysql.username'))
        ->and($connection['username'])->not->toBeNull();
});
