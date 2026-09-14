<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureTenantIsUsable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ScopeSessionToTenant;
use App\Jobs\DeleteTenantDatabase;
use App\Models\Tenant;
use App\Support\Tenancy\TenantDatabaseGrants;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            // Sengaja kosong. Provisioning tidak digantungkan pada event
            // `TenantCreated`, karena alurnya butuh urutan langkah yang eksplisit
            // dan rollback kalau salah satunya gagal — lihat
            // App\Services\Tenancy\TenantProvisioner.
            Events\TenantCreated::class => [],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    // Varian sendiri: melewatkan DROP DATABASE untuk tenant yang
                    // databasenya memang belum pernah dibuat. Lihat kelasnya.
                    DeleteTenantDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            // Setiap migrasi tenant — saat provisioning maupun deploy — diikuti
            // sinkronisasi hak user runtime, supaya tabel baru langsung
            // mendapat hak per tabel yang benar (dan audit_logs tetap
            // append-only). Lihat App\Support\Tenancy\TenantDatabaseGrants.
            Events\DatabaseMigrated::class => [
                fn (Events\DatabaseMigrated $event) => app(TenantDatabaseGrants::class)->sync($event->tenant),
            ],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [
                fn (Events\DatabaseRolledBack $event) => app(TenantDatabaseGrants::class)->sync($event->tenant),
            ],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->bootEvents();
        $this->makeTenancyMiddlewareHighestPriority();
        $this->nameTenantDatabasesAfterSlug();
        $this->generateTenantDatabaseCredentials();
        $this->renderFriendlyPageForUnknownSubdomains();
        $this->resetProcessStateOnTenantSwitch();
    }

    /**
     * Singleton yang menyimpan state tenant harus di-reset setiap kali konteks
     * berpindah.
     *
     * Dalam request HTTP biasa ini jarang terlihat — satu request, satu tenant,
     * proses PHP-FPM mati sesudahnya. Tapi worker antrian, perintah artisan yang
     * mem-provision beberapa tenant, dan suite test menjalankan BANYAK tenant di
     * satu proses. Di sana singleton yang terlanjur dibuat untuk tenant A akan
     * dipakai lagi untuk tenant B tanpa satu pun error.
     */
    protected function resetProcessStateOnTenantSwitch(): void
    {
        $centralPermissionCacheKey = config('permission.cache.key');

        Event::listen(Events\TenancyBootstrapped::class, function (Events\TenancyBootstrapped $event) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            // Rute tenant memakai domain `{tenant}.<domain-pusat>`; tanpa default
            // ini setiap route() dari dalam tenant — termasuk tautan di email
            // undangan dan redirect ke halaman login — gagal karena parameter
            // `tenant` tidak diisi.
            URL::defaults(['tenant' => $tenant->slug]);

            // spatie/laravel-permission mengambil store cache lewat
            // CacheManager::store(), yang TIDAK melewati tag tenant dari
            // CacheTenancyBootstrapper. Tanpa kunci per tenant, role dan
            // permission tenant A tersaji dari cache untuk tenant B.
            config(['permission.cache.key' => 'spatie.permission.cache.tenant.'.$tenant->getTenantKey()]);

            $this->defineMigratorConnection($tenant);
            $this->forgetTenantBoundSingletons();
        });

        // Guard auth di-reset HANYA saat kembali ke pusat, bukan saat bootstrap.
        // Pindah dari tenant A ke B selalu melewati titik ini (paket mengakhiri
        // tenancy A dulu), jadi user A tidak pernah terbawa ke B. Me-reset saat
        // bootstrap justru membuang user yang sah milik request itu sendiri.
        Event::listen(Events\RevertedToCentralContext::class, function () {
            // User id 7 di tenant A bukan orang yang sama dengan user id 7 di tenant B.
            $this->app->make('auth')->forgetGuards();
        });

        Event::listen(Events\RevertedToCentralContext::class, function () use ($centralPermissionCacheKey) {
            URL::defaults(['tenant' => null]);
            DB::purge('tenant_migrator');
            config(['database.connections.tenant_migrator' => null]);
            config(['permission.cache.key' => $centralPermissionCacheKey]);

            $this->forgetTenantBoundSingletons();
        });
    }

    /**
     * Koneksi berkredensial admin ke database tenant yang sedang aktif, khusus
     * untuk migrasi (`tenancy.migration_parameters`). User runtime tenant tidak
     * punya hak DDL.
     *
     * Host dan port diambil dari baris tenant, bukan dari koneksi pusat, supaya
     * tenant yang dipindah ke server DB lain tetap dimigrasi di server yang
     * benar. Kredensialnya kredensial `tenancy_admin` — kalau server lain itu
     * punya akun admin berbeda, di sinilah ia perlu dibaca.
     */
    protected function defineMigratorConnection(Tenant $tenant): void
    {
        $admin = config('database.connections.'.config('tenancy.database.admin_connection'));

        config(['database.connections.tenant_migrator' => array_merge($admin, array_filter([
            'host' => $tenant->db_host,
            'port' => $tenant->db_port,
        ]), [
            'database' => $tenant->database()->getName(),
        ])]);

        DB::purge('tenant_migrator');
    }

    protected function forgetTenantBoundSingletons(): void
    {
        // initializeCache() juga membuang koleksi permission di memori.
        $this->app->make(PermissionRegistrar::class)->initializeCache();

        // Broker password memegang objek koneksi database yang di-resolve saat
        // pertama dipakai. Token undangan tenant B bisa tertulis ke database
        // tenant A kalau broker lama dipakai ulang.
        $this->app->forgetInstance('auth.password');
        $this->app->forgetInstance('auth.password.broker');
        Password::clearResolvedInstance('auth.password');
    }

    /**
     * Nama database tenant memakai slug, bukan UUID.
     *
     * `klinik_melati` terbaca saat menelusuri `SHOW DATABASES` atau daftar
     * backup; `klinik_01a074a9-ed6a-70a4-...` tidak. UUID tetap menjadi kunci
     * primer — yang diganti hanya labelnya di sisi MySQL.
     */
    protected function nameTenantDatabasesAfterSlug(): void
    {
        DatabaseConfig::generateDatabaseNamesUsing(function (Tenant $tenant): string {
            return config('tenancy.database.prefix')
                .str_replace('-', '_', $tenant->slug)
                .config('tenancy.database.suffix');
        });
    }

    /**
     * Kredensial user MySQL per tenant.
     *
     * Password sengaja alfanumerik saja: manager paket menyisipkannya langsung
     * ke dalam `CREATE USER ... IDENTIFIED BY '...'` tanpa escaping, sehingga
     * satu tanda kutip di password akan mematahkan — atau lebih buruk,
     * menyuntikkan — query itu.
     *
     * Username memuat potongan slug supaya terbaca saat menelusuri
     * `mysql.user`, ditambah akhiran acak supaya tenant yang dihapus lalu dibuat
     * ulang dengan slug sama tidak mewarisi grant lama. Batas MySQL 32 karakter.
     */
    protected function generateTenantDatabaseCredentials(): void
    {
        DatabaseConfig::generateUsernamesUsing(function (Tenant $tenant): string {
            $slug = substr(str_replace('-', '_', $tenant->slug), 0, 20);

            return config('tenancy.database.user_prefix').$slug.'_'.Str::lower(Str::random(6));
        });

        DatabaseConfig::generatePasswordsUsing(
            fn (): string => Str::password(40, symbols: false)
        );
    }

    /**
     * Subdomain yang tidak terdaftar harus berujung ke halaman 404 yang
     * menjelaskan, bukan stack trace.
     *
     * Ini bukan sekadar kerapian: stack trace di subdomain yang salah ketik
     * membocorkan struktur internal aplikasi ke siapa pun yang menebak-nebak
     * nama klinik.
     */
    protected function renderFriendlyPageForUnknownSubdomains(): void
    {
        Middleware\InitializeTenancyBySubdomain::$onFail = function ($exception, Request $request, $next) {
            return Inertia::render('Errors/TenantNotFound', [
                'host' => $request->getHost(),
            ])->toResponse($request)->setStatusCode(404);
        };
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        $kernel = $this->app[\Illuminate\Contracts\Http\Kernel::class];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $kernel->prependToMiddlewarePriority($middleware);
        }

        // Laravel mengurutkan ulang middleware yang ada di daftar prioritasnya.
        // `Authenticate` ada di daftar itu, gerbang status tenant tidak — jadi
        // tanpa baris di bawah, `auth` dipindah ke DEPAN EnsureTenantIsUsable
        // dan klinik yang ditangguhkan mengarahkan tamu ke halaman login
        // alih-alih menjelaskan bahwa aksesnya dihentikan.
        //
        // Urutan akhir: identifikasi tenant → sesi → props Inertia → cakupan
        // sesi → status tenant → autentikasi.
        foreach ([HandleInertiaRequests::class, ScopeSessionToTenant::class, EnsureTenantIsUsable::class] as $middleware) {
            $kernel->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, $middleware);
        }
    }
}
