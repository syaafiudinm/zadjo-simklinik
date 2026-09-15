<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Support\Facades\Password;

/**
 * Mengirim tautan reset password dari worker.
 *
 * Dua alasan tidak dikirim langsung di request:
 *
 * - Waktu respons. Mengirim email butuh ratusan milidetik; tidak mengirim
 *   (email tak terdaftar) butuh beberapa. Selisih itu cukup untuk menebak
 *   siapa saja staf klinik lewat halaman lupa password, walau pesannya sama.
 *   Kini request selalu hanya men-dispatch job ini.
 *
 * - Token tidak boleh berada di payload antrian yang terlihat di Horizon.
 *   Token dibuat broker di dalam worker; payload hanya berisi email.
 */
class SendPasswordResetLink extends TenantAwareJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public string $email) {}

    public function handleForTenant(): void
    {
        Password::broker('users')->sendResetLink(['email' => $this->email]);
    }
}
