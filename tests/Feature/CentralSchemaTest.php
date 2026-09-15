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

it('memakai host template untuk kolom db_host yang kosong', function () {
    // Kolom null tidak boleh menimpa host koneksi template dengan null.
    $tenant = Tenant::factory()->withoutDatabase()->create([
        'db_username' => 'sk_uji', 'db_password' => 'rahasia',
    ]);
    $tenant->db_host = null;

    expect($tenant->database()->connection()['host'])
        ->toBe(config('database.connections.tenancy_admin.host'));
});

it('menolak membuka koneksi tenant yang tidak punya user MySQL sendiri', function () {
    // Template koneksi tenant adalah `tenancy_admin`. Kalau kredensial kosong
    // dibiarkan jatuh ke template, tenant itu berjalan dengan hak admin server
    // — membaca database semua klinik — tanpa satu pun error.
    $tenant = Tenant::factory()->withoutDatabase()->create([
        'db_username' => null,
        'db_password' => null,
    ]);

    expect(fn () => $tenant->database()->connection())
        ->toThrow(LogicException::class, 'tidak punya user MySQL sendiri');
});
