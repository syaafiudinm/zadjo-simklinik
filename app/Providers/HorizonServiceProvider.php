<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // ⏳ Notifikasi job gagal ke operator menyusul bersama panel vendor (S1-10).
    }

    /**
     * Mengganti gate bawaan Horizon, yang membuka dashboard tanpa syarat di
     * lingkungan `local`. Akses ditentukan HorizonBasicAuth; gate ini hanya
     * memastikan middleware itu memang sudah berjalan untuk request ini.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request) => $request->attributes->get(HorizonBasicAuth::PASSED) === true);
    }

    protected function gate(): void
    {
        // Tidak dipakai: lihat authorization().
    }
}
