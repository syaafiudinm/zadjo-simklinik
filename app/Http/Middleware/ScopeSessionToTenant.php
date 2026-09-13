<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengikat sesi ke tenant yang menerbitkannya.
 *
 * Pengganti `Stancl\Tenancy\Middleware\ScopeSessions`. Versi paket menolak sesi
 * milik tenant lain dengan `abort(403)` — tapi membiarkan isi sesinya utuh.
 * Halaman 403 lalu dirender dengan props bersama Inertia, yang me-resolve
 * `$request->user()` dari sesi itu: `login_web_… = 1` dibaca dari database
 * tenant yang SEDANG diakses. Pemegang cookie klinik A menerima nama, email,
 * dan daftar permission user ber-id 1 di klinik B.
 *
 * Di sini sesi asing dibakar (dikosongkan, id diganti, sesi lama dihapus dari
 * store) sebelum apa pun sempat membacanya, lalu request diteruskan sebagai
 * tamu biasa. Cookie sesi terikat host, jadi di browser yang wajar kondisi ini
 * tidak pernah terjadi — yang memicunya adalah cookie yang dipindah tangan.
 */
class ScopeSessionToTenant
{
    public const TENANT_KEY = '_tenant_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            throw new TenancyNotInitializedException('Tenancy harus diinisialisasi sebelum sesi di-scope.');
        }

        $session = $request->session();
        $tenantId = tenant()->getTenantKey();
        $owner = $session->get(self::TENANT_KEY);

        if ($owner !== null && $owner !== $tenantId) {
            Log::warning('Sesi milik tenant lain ditolak dan dibatalkan.', [
                'tenant' => $tenantId,
                'session_tenant' => $owner,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $session->invalidate();

            app(AuditLogger::class)->record(AuditEvent::SessionRejected, context: [
                'session_tenant' => $owner,
                'path' => $request->path(),
            ]);
        }

        $session->put(self::TENANT_KEY, $tenantId);

        return $next($request);
    }
}
