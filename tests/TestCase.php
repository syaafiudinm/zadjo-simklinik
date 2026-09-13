<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    /**
     * Migrasi central hanya dijalankan sekali per proses test.
     *
     * RefreshDatabase sengaja TIDAK dipakai di suite ini. Pembuatan database
     * tenant adalah DDL (`CREATE DATABASE`), dan DDL memicu implicit commit di
     * MySQL — transaksi pembungkus RefreshDatabase akan patah diam-diam dan
     * baris uji bocor antar test tanpa satu pun error. Jadi pembersihannya
     * dilakukan eksplisit: hapus tenant beserta databasenya di tiap test.
     */
    protected static bool $centralMigrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::$centralMigrated) {
            $this->sweepOrphanedTestDatabases();
            $this->artisan('migrate:fresh', ['--force' => true])->run();
            static::$centralMigrated = true;
        }

        $this->dropAllTenants();
        $this->flushTestCache();
    }

    protected function tearDown(): void
    {
        $this->dropAllTenants();

        parent::tearDown();
    }

    /**
     * Membuat tenant lewat jalur provisioning yang sama dengan produksi:
     * database, user MySQL, migrasi, seed role & poli, admin, undangan.
     */
    public function createTenant(string $slug, array $attributes = []): Tenant
    {
        $provisioner = app(TenantProvisioner::class);

        $tenant = $provisioner->register($slug, 'Klinik '.$slug, "admin@{$slug}.test");
        $provisioner->provision($tenant);

        $tenant->refresh();

        if ($attributes !== []) {
            $tenant->update($attributes);
        }

        return $tenant->refresh();
    }

    /**
     * Membuat pengguna berperan tertentu di dalam database tenant.
     */
    public function createTenantUser(Tenant $tenant, string $role, array $attributes = []): User
    {
        return $tenant->run(function () use ($role, $attributes) {
            $user = User::factory()->create(array_merge(['activated_at' => now()], $attributes));
            $user->assignRole($role);

            return $user;
        });
    }

    public function adminOf(Tenant $tenant): User
    {
        return $tenant->run(fn () => User::where('email', "admin@{$tenant->slug}.test")->firstOrFail());
    }

    /** Hostname yang melayani tenant tersebut. */
    public function tenantUrl(Tenant|string $tenant, string $path = '/'): string
    {
        $slug = $tenant instanceof Tenant ? $tenant->slug : $tenant;

        // Tanpa garis miring akhir untuk beranda, sama dengan hasil route().
        return 'http://'.$slug.'.'.config('tenancy.primary_central_domain').($path === '/' ? '' : $path);
    }

    public function centralUrl(string $path = '/'): string
    {
        return 'http://'.config('tenancy.primary_central_domain').$path;
    }

    /**
     * Mensimulasikan request berikutnya datang di proses PHP yang baru.
     *
     * Di test, satu instance aplikasi melayani semua request. Dua singleton
     * membawa state antar request yang di PHP-FPM tidak pernah bertahan:
     *
     * - guard auth menyimpan user yang sudah di-resolve, sehingga test isolasi
     *   sesi bisa hijau hanya karena user masih menempel di guard;
     * - Store sesi MENGGABUNGKAN atribut request sebelumnya ke sesi baru,
     *   sehingga `_tenant_id` tenant A terbawa ke request tenant B dan
     *   ScopeSessions menolaknya dengan 403 — perilaku yang benar untuk sesi
     *   curian, tapi bukan yang sedang diuji.
     */
    public function freshProcess(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app('auth')->forgetGuards();

        // Dikosongkan, bukan dibuang: singleton lain (Redirector, untuk
        // withErrors()) memegang referensi ke objek Store yang sama. Membuang
        // instansinya membuat error validasi tertulis ke store yatim.
        app('session.store')->flush();
    }

    private function dropAllTenants(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (! app()->bound('db') || ! $this->centralTablesExist()) {
            return;
        }

        // Menghapus model Tenant memicu job penghapusan database, jadi
        // database dan user MySQL-nya ikut hilang — bukan hanya barisnya.
        Tenant::all()->each->delete();
    }

    /**
     * Sisa run test yang terhenti di tengah (Ctrl+C, fatal error) meninggalkan
     * database dan user MySQL yang tidak lagi tercatat di tabel `tenants`.
     * Prefix khusus test (phpunit.xml) memastikan sapuan ini tidak pernah
     * menyentuh milik lingkungan pengembangan.
     */
    private function sweepOrphanedTestDatabases(): void
    {
        $dbPrefix = (string) config('tenancy.database.prefix');
        $userPrefix = (string) config('tenancy.database.user_prefix');

        if (! str_contains($dbPrefix, 'test') || ! str_contains($userPrefix, 't')) {
            return;
        }

        $central = DB::connection(config('tenancy.database.central_connection'));
        $like = fn (string $prefix) => str_replace('_', '\\_', $prefix).'%';

        foreach ($central->select('SELECT SCHEMA_NAME AS name FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?', [$like($dbPrefix)]) as $row) {
            $central->statement("DROP DATABASE IF EXISTS `{$row->name}`");
        }

        foreach ($central->select('SELECT user FROM mysql.user WHERE user LIKE ?', [$like($userPrefix)]) as $row) {
            $central->statement("DROP USER IF EXISTS `{$row->user}`@`%`");
        }
    }

    /**
     * Cache & sesi test memakai database Redis terpisah (lihat phpunit.xml),
     * jadi pengosongan ini tidak pernah menyentuh milik pengembangan.
     */
    private function flushTestCache(): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        Redis::connection(config('cache.stores.redis.connection', 'cache'))->flushdb();
    }

    private function centralTablesExist(): bool
    {
        try {
            return DB::connection(config('tenancy.database.central_connection'))
                ->getSchemaBuilder()
                ->hasTable('tenants');
        } catch (\Throwable) {
            return false;
        }
    }
}
