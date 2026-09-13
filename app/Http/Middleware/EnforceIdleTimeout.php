<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-M21.6 — logout otomatis setelah idle, dapat diatur per klinik.
 *
 * Tidak mengandalkan SESSION_LIFETIME. Umur sesi di store bersifat global
 * untuk seluruh aplikasi dan perilakunya bergantung driver; batas idle di sini
 * dibaca per tenant dan ditegakkan dengan cap waktu di dalam sesi, sehingga
 * bisa diuji dan tidak berubah kalau driver sesi diganti.
 *
 * Penegakan di server hanya berbunyi pada request berikutnya. Supaya layar
 * berisi data pasien tidak tetap terbuka di meja yang ditinggal, frontend
 * juga menghitung idle dari aktivitas pengguna dan logout sendiri (lihat
 * resources/js/Lib/useIdleLogout.ts).
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        /** @var Tenant $tenant */
        $tenant = tenant();
        $limit = $tenant->idleTimeoutMinutes() * 60;
        $last = $request->session()->get(self::SESSION_KEY);

        if (is_int($last) && now()->getTimestamp() - $last > $limit) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', self::expiredMessage());
        }

        self::touch($request);

        return $next($request);
    }

    public static function touch(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    public static function expiredMessage(): string
    {
        $minutes = tenant() instanceof Tenant ? tenant()->idleTimeoutMinutes() : config('simklinik.idle_timeout_minutes');

        return "Sesi berakhir karena tidak ada aktivitas selama {$minutes} menit. Silakan masuk kembali.";
    }
}
