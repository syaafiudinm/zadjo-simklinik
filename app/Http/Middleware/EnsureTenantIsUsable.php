<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang status tenant. Berjalan setelah tenancy diinisialisasi.
 *
 * Aturan yang tidak boleh dilanggar (PRD FR-M23.4): tidak ada status yang
 * memblokir akses BACA rekam medis karena urusan tagihan. Tunggakan
 * menurunkan tenant ke `read_only`, bukan mematikannya.
 */
class EnsureTenantIsUsable
{
    /** Metode HTTP yang dianggap mengubah data. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Rute tenant dicocokkan lewat pola domain `{tenant}.<domain-pusat>`,
        // jadi Laravel menyuntikkan fragmen subdomain sebagai parameter rute
        // dan meneruskannya sebagai argumen pertama ke setiap controller.
        // Tenant sudah tersedia lewat helper tenant(); parameter itu hanya akan
        // mengacaukan signature setiap action, jadi dibuang di sini.
        $request->route()?->forgetParameter('tenant');

        /** @var Tenant|null $tenant */
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return $this->page($request, 'Errors/TenantNotFound', 404);
        }

        if ($tenant->status === TenantStatus::Provisioning) {
            return $this->page($request, 'Errors/TenantProvisioning', 503, [
                'tenantName' => $tenant->name,
            ]);
        }

        if (! $tenant->status->allowsAccess()) {
            return $this->page($request, 'Errors/TenantSuspended', 403, [
                'tenantName' => $tenant->name,
            ]);
        }

        if (! $tenant->status->allowsWrites() && in_array($request->method(), self::WRITE_METHODS, true)) {
            abort(403, 'Klinik ini sedang berstatus hanya-baca. Data lama tetap dapat dibuka, tetapi perubahan baru tidak dapat disimpan.');
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, string $component, int $status, array $props = []): Response
    {
        return Inertia::render($component, $props)
            ->toResponse($request)
            ->setStatusCode($status);
    }
}
