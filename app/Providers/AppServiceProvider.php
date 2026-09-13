<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Akun yang membuka rekam medis. Cek kebocoran (`uncompromised()`)
        // sengaja belum dipakai: ia memanggil API eksternal saat validasi, dan
        // klinik dengan internet tidak stabil tidak boleh gagal ganti password
        // karena itu.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Kunci memuat id tenant secara eksplisit — RateLimiter tidak melewati
        // tag cache tenant. Lihat LoginRequest::throttleKey().
        RateLimiter::for('tenant-password-email', function (Request $request) {
            return Limit::perMinute(6)->by((tenant()?->getTenantKey() ?? 'central').'|'.$request->ip());
        });
    }
}
