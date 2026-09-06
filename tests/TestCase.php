<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
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
     * Membuat tenant lengkap dengan database dan subdomainnya.
     */
    protected function createTenant(string $slug, array $attributes = []): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'slug' => $slug,
            'name' => 'Klinik '.$slug,
            'status' => \App\Enums\TenantStatus::Active,
        ], $attributes));

        $tenant->domains()->create(['domain' => $slug]);

        return $tenant->refresh();
    }

    /** Hostname yang melayani tenant tersebut. */
    protected function tenantUrl(Tenant|string $tenant, string $path = '/'): string
    {
        $slug = $tenant instanceof Tenant ? $tenant->slug : $tenant;

        return 'http://'.$slug.'.'.config('tenancy.primary_central_domain').$path;
    }

    protected function centralUrl(string $path = '/'): string
    {
        return 'http://'.config('tenancy.primary_central_domain').$path;
    }

    private function dropAllTenants(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (! app()->bound('db') || ! $this->centralTablesExist()) {
            return;
        }

        // Menghapus model Tenant memicu job DeleteDatabase, jadi database
        // fisiknya ikut hilang — bukan hanya barisnya.
        Tenant::all()->each->delete();
    }

    /**
     * Cache test memakai database Redis terpisah (lihat phpunit.xml), jadi
     * pengosongan ini tidak pernah menyentuh cache pengembangan.
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
