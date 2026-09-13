<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Tenant;
use App\Support\Tenancy\TenantDatabaseManager;
use App\Support\Tenancy\UuidV7Generator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UuidV7Generator::class,
    'domain_model' => Domain::class,

    /**
     * Domain yang melayani aplikasi pusat: landing, panel vendor, dan health check.
     * Subdomain di bawah `primary_central_domain` diperlakukan sebagai tenant.
     *
     * Di lokal dipakai `.localhost` karena browser modern meresolve *.localhost ke
     * 127.0.0.1 tanpa dnsmasq maupun /etc/hosts — lihat Sprint 1 §8.
     */
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CENTRAL_DOMAINS', 'simklinik.localhost,localhost,127.0.0.1'))
    ))),

    /**
     * Domain induk yang subdomainnya dipakai untuk identifikasi tenant.
     * Rute tenant dibatasi ke pola `{tenant}.<primary_central_domain>` sehingga
     * rute pusat dan rute tenant terpisah di level pencocokan rute, bukan sekadar
     * di middleware.
     */
    'primary_central_domain' => env('CENTRAL_DOMAIN', 'simklinik.localhost'),

    /**
     * Subdomain yang tidak boleh dipakai sebagai slug tenant. Dipakai sebagai
     * negative lookahead pada constraint rute tenant, sehingga mis.
     * `admin.simklinik.localhost` jatuh ke rute pusat, bukan dianggap tenant.
     */
    'reserved_subdomains' => [
        'admin', 'www', 'api', 'app', 'mail', 'static', 'assets', 'cdn',
        'status', 'support', 'billing', 'horizon', 'demo',
    ],

    /**
     * Bootstrapper dijalankan saat tenancy diinisialisasi; tugasnya membuat
     * fitur Laravel sadar-tenant.
     *
     * Keempatnya aktif sejak sekarang, bukan nanti: cache dan filesystem yang
     * bocor antar tenant jauh lebih mahal ditambal setelah ada data nyata.
     */
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        // RedisTenancyBootstrapper butuh ekstensi phpredis dan hanya relevan
        // untuk panggilan Redis langsung. Cache sudah ditangani bootstrapper di atas.
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'mysql'),

        /**
         * Koneksi yang dipakai sebagai template koneksi tenant yang dibuat dinamis.
         * Jangan menamai koneksi template `tenant` — nama itu dipakai paket.
         */
        'template_tenant_connection' => null,

        /**
         * Nama database tenant = prefix + slug + suffix (lihat AppServiceProvider).
         * Slug dipakai, bukan UUID, supaya nama database terbaca manusia saat
         * troubleshooting produksi jam 2 pagi.
         */
        'prefix' => env('TENANCY_DB_PREFIX', 'simklinik_'),
        'suffix' => env('TENANCY_DB_SUFFIX', ''),

        /**
         * Awalan user MySQL per tenant. Dibedakan antar environment (test
         * memakai `skt_`) supaya pembersihan user yatim di suite test tidak
         * pernah menyentuh user milik lingkungan pengembangan.
         */
        'user_prefix' => env('TENANCY_DB_USER_PREFIX', 'sk_'),

        'managers' => [
            'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
            // User MySQL per tenant dengan grant terbatas ke databasenya sendiri.
            'mysql' => TenantDatabaseManager::class,
            'mariadb' => TenantDatabaseManager::class,
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,

        /**
         * Dimatikan: `asset()` tetap menunjuk aset build Vite yang sama untuk
         * semua tenant. Menyalakannya mengarahkan asset() ke route bawaan paket
         * (`stancl.tenancy.asset`) yang sengaja tidak didaftarkan di sini.
         * Berkas milik tenant (nanti: lampiran, hasil scan) dilayani lewat
         * controller sendiri yang menegakkan otorisasi, bukan lewat asset().
         */
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    /**
     * Rute bawaan paket (tenant asset route) dimatikan; rute tenant didaftarkan
     * sendiri di bootstrap/app.php supaya urutan pendaftarannya terkendali.
     */
    'routes' => false,

    'migration_parameters' => [
        '--force' => true,
        // Migrasi tenant berjalan dengan kredensial admin lewat koneksi
        // `tenant_migrator` (didefinisikan saat tenancy aktif, lihat
        // TenancyServiceProvider). User runtime tenant tidak punya hak DDL.
        '--database' => 'tenant_migrator',
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantDatabaseSeeder',
    ],
];
