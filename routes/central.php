<?php

declare(strict_types=1);

use App\Http\Middleware\PreventAccessFromTenantDomains;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Rute Pusat — landing & panel vendor
|--------------------------------------------------------------------------
|
| Dilayani dari domain yang terdaftar di `tenancy.central_domains`. Tidak boleh
| ada satu pun rute di sini yang menyentuh data klinis: koneksi default di
| konteks ini adalah database pusat, dan memang harus tetap begitu.
|
| Panel vendor (S1-10) menyusul.
|
*/

Route::middleware(['web', PreventAccessFromTenantDomains::class])->group(function () {
    Route::get('/', function () {
        return Inertia::render('Central/Home', [
            // Hanya metadata tenant — nama dan status. Data klinis tidak pernah
            // dibaca dari konteks pusat (PRD §4.2: vendor adalah Prosesor,
            // bukan Pengendali Data Pribadi).
            'tenants' => Tenant::query()
                ->orderBy('name')
                ->get(['slug', 'name', 'status'])
                ->map(fn (Tenant $tenant) => [
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'status' => $tenant->status->value,
                    'statusLabel' => $tenant->status->label(),
                    'url' => 'http://'.$tenant->primaryDomain().(request()->getPort() === 80 ? '' : ':'.request()->getPort()),
                ]),
        ]);
    })->name('central.home');
});
