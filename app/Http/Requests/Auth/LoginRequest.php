<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Percobaan gagal per kombinasi (klinik, email, IP) sebelum dikunci. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Tidak ada "ingat saya". Komputer klinik dipakai bergantian oleh banyak
     * petugas; sesi yang bertahan berhari-hari di meja pendaftaran adalah
     * celah akses rekam medis, bukan kenyamanan.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            // Pesan yang sama untuk email tak terdaftar dan password salah,
            // supaya form login tidak bisa dipakai menebak siapa staf klinik.
            throw ValidationException::withMessages([
                'email' => 'Email atau password tidak sesuai.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    /**
     * Kunci throttle memuat id tenant secara EKSPLISIT.
     *
     * RateLimiter mengambil store cache lewat CacheManager::driver(), yang
     * tidak melewati tag tenant dari CacheTenancyBootstrapper. Tanpa id tenant
     * di kunci, lima percobaan gagal ke `dr.aditya@...` di klinik A ikut
     * mengunci akun dengan email yang sama di klinik B.
     */
    public function throttleKey(): string
    {
        return 'login:'.tenant()->getTenantKey()
            .'|'.Str::transliterate(Str::lower($this->string('email')->toString()))
            .'|'.$this->ip();
    }
}
