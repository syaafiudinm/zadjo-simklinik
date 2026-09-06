<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sisi sebaliknya dari isolasi rute: rute pusat tidak boleh dilayani dari
 * subdomain tenant.
 *
 * Rute tenant sudah terpisah lewat pola domainnya, tapi rute pusat sengaja
 * didaftarkan tanpa batasan domain (supaya nama rutenya unik dan bisa
 * di-cache). Tanpa penjaga ini, path yang hanya ada di rute pusat — misalnya
 * panel vendor — akan ikut terjawab di `klinik-a.simklinik.id`.
 */
class PreventAccessFromTenantDomains
{
    public function handle(Request $request, Closure $next): Response
    {
        $centralDomains = (array) config('tenancy.central_domains');

        if (in_array($request->getHost(), $centralDomains, true)) {
            return $next($request);
        }

        return Inertia::render('Errors/TenantNotFound', [
            'host' => $request->getHost(),
        ])->toResponse($request)->setStatusCode(404);
    }
}
