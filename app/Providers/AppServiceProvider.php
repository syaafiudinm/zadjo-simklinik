<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\AuditSecurityEvents;
use App\Support\QueueTimeoutGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

        Event::subscribe(AuditSecurityEvents::class);

        // Worker menolak start kalau retry_after tidak lebih besar dari timeout
        // job terlama. Test hanya bisa menjaga nilai bawaan di config; `.env`
        // produksi yang salah hanya tertangkap di sini, sebelum ada provisioning
        // ganda yang berjalan bersamaan.
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (in_array($event->command, QueueTimeoutGuard::WORKER_COMMANDS, true)) {
                QueueTimeoutGuard::assertSafe();
            }
        });

        // Jejak audit dari worker ditandai kanal `queue`. Didaftarkan setelah
        // listener Context bawaan Laravel, yang mengganti isi Context dengan
        // milik request pemicu (termasuk request_id-nya) saat job mulai.
        // Setiap job yang di-dispatch dari dalam klinik diberi tag tenant —
        // termasuk job yang tidak kita tulis sendiri — supaya di Horizon bisa
        // disaring per klinik. QueueTenancyBootstrapper menambahkan
        // `tenant_id` ke payload yang sama untuk menginisialisasi konteks.
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) {
            if (! tenancy()->initialized) {
                return [];
            }

            return ['tags' => array_values(array_unique([
                ...($payload['tags'] ?? []),
                'tenant:'.tenant('slug'),
            ]))];
        });

        Queue::before(fn () => Context::addHidden('audit_channel', 'queue'));
        Queue::after(fn () => Context::forgetHidden('audit_channel'));
        Queue::failing(fn () => Context::forgetHidden('audit_channel'));
    }
}
