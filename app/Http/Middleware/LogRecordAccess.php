<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-M22.1 — mencatat akses baca rekam medis tertentu.
 *
 *     Route::get('/patients/{patient}', ...)->middleware('audit.access:patient');
 *
 * Dipasang di rute yang membuka SATU rekam medis, bukan di rute daftar.
 * Mencatat setiap baris yang tampil di tabel daftar pasien menghasilkan
 * ratusan baris log per halaman tanpa menjawab pertanyaan yang sebenarnya
 * diajukan saat sengketa: "siapa yang membuka rekam medis pasien ini?"
 *
 * Dicatat hanya kalau responsnya berhasil. Akses yang ditolak dicatat
 * terpisah sebagai `access_denied`.
 */
class LogRecordAccess
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $parameter): Response
    {
        $response = $next($request);

        $record = $request->route($parameter);

        if ($response->isSuccessful() && $record instanceof Model) {
            $this->audit->recordAccess($record, [
                'route' => $request->route()?->getName(),
            ]);
        }

        return $response;
    }
}
