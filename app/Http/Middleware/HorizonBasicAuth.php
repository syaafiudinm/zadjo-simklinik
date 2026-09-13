<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang dashboard Horizon sampai login vendor tersedia (S1-10).
 *
 * Horizon memperlihatkan payload job, dan payload job klinik berisi identitas
 * pengguna. Karena itu:
 *
 * - fail-closed: kredensial kosong berarti tidak ada yang bisa masuk,
 *   termasuk di lingkungan lokal — bawaan Horizon membuka dashboard tanpa
 *   syarat di `local`;
 * - percobaan gagal dibatasi per IP.
 *
 * Menandai request yang lolos supaya gate Horizon (HorizonServiceProvider)
 * juga menolak kalau middleware ini suatu hari tercabut dari konfigurasi.
 */
class HorizonBasicAuth
{
    public const PASSED = 'horizon.basic_auth_passed';

    private const MAX_ATTEMPTS = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) config('horizon.basic_auth.username');
        $password = (string) config('horizon.basic_auth.password');

        if ($username === '' || $password === '') {
            abort(403, 'Dashboard antrian belum dikonfigurasi (HORIZON_BASIC_AUTH_USERNAME / HORIZON_BASIC_AUTH_PASSWORD).');
        }

        $throttleKey = 'horizon-basic-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            abort(429, 'Terlalu banyak percobaan masuk.');
        }

        $valid = hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $valid) {
            RateLimiter::hit($throttleKey, 60);

            return response('Autentikasi diperlukan.', 401, [
                'WWW-Authenticate' => 'Basic realm="SIMKlinik Horizon", charset="UTF-8"',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->attributes->set(self::PASSED, true);

        return $next($request);
    }
}
