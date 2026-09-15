<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRecordAccess;
use App\Support\Audit\AuditLogger;
use App\Support\ForbiddenMessage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

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
        $middleware->web(prepend: [
            AssignRequestId::class,
        ], append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'audit.access' => LogRecordAccess::class,
        ]);

        // Hanya aplikasi klinik yang punya halaman login. Di domain pusat
        // (sampai panel vendor S1-10 ada) tamu dikembalikan ke beranda.
        $middleware->redirectGuestsTo(fn () => tenancy()->initialized ? route('login') : '/');
        $middleware->redirectUsersTo(fn () => tenancy()->initialized ? route('tenant.dashboard') : '/');

        // Prioritas middleware identifikasi tenant diatur di
        // App\Providers\TenancyServiceProvider: ia harus berjalan sebelum
        // apa pun yang menyentuh database, termasuk StartSession.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return $response;
            }

            // 403 selalu dirender sebagai halaman yang menjelaskan, juga di
            // lokal: "akses langsung ke URL tanpa permission" adalah bagian
            // skrip demo sprint, dan halaman error Symfony bukan jawaban yang
            // pantas untuk petugas klinik.
            if ($response->getStatusCode() === 403) {
                // Percobaan membuka halaman tanpa hak oleh pengguna yang sudah
                // login dicatat. Tamu tidak: rute tenant mengarahkan tamu ke
                // login, jadi 403 bagi tamu hanya kebisingan.
                if (tenancy()->initialized && auth()->guard('web')->hasUser()) {
                    app(AuditLogger::class)->record(AuditEvent::AccessDenied, context: [
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'route' => $request->route()?->getName(),
                    ]);
                }

                return Inertia::render('Errors/Forbidden', [
                    'message' => ForbiddenMessage::for($e),
                ])->toResponse($request)->setStatusCode(403);
            }

            // Token CSRF kedaluwarsa — biasanya tab yang dibiarkan terbuka
            // melewati batas idle. Kembalikan ke halaman sebelumnya dengan
            // pesan, bukan layar "419 Page Expired".
            if ($response->getStatusCode() === 419) {
                return back()->with('error', 'Halaman sudah kedaluwarsa. Silakan coba lagi.');
            }

            return $response;
        });
    })
    ->create();
