<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\DeleteTenantDatabase;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    // Jobs\SeedDatabase::class akan menyusul di S1-04 bersama
                    // pembuatan akun admin dan rollback saat gagal.
                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                    // Sinkron untuk sekarang: alur provisioning berbasis antrian —
                    // beserta rollback dan penanganan kegagalannya — adalah S1-04.
                })->shouldBeQueued(false),
            ],
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
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
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
        $this->renderFriendlyPageForUnknownSubdomains();
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

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[\Illuminate\Contracts\Http\Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
