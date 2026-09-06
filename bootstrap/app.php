<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // URUTAN PENTING. Router memilih rute pertama yang cocok, dan rute
            // pusat sengaja tidak dibatasi domain. Kalau rute pusat didaftarkan
            // lebih dulu, `/` di subdomain tenant akan dilayani oleh landing
            // page pusat — tenancy tidak pernah diinisialisasi, dan halaman itu
            // membaca database yang salah tanpa satu pun error.
            //
            // Rute tenant lebih spesifik (punya batasan domain), jadi ia harus
            // mendapat giliran pertama.
            Route::group([], base_path('routes/tenant.php'));
            Route::group([], base_path('routes/central.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Prioritas middleware identifikasi tenant diatur di
        // App\Providers\TenancyServiceProvider: ia harus berjalan sebelum
        // apa pun yang menyentuh database, termasuk StartSession.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
