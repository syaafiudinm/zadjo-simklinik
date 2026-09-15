<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var Tenant|null $tenant */
        $tenant = tenant();

        return [
            ...parent::share($request),

            'appName' => config('app.name'),

            'auth' => fn () => $this->auth($request),

            // `null` di konteks pusat. Frontend memakai ini untuk membedakan
            // landing page vendor dari aplikasi klinik, dan untuk memunculkan
            // banner hanya-baca tanpa perlu tiap halaman mengirimkannya sendiri.
            'tenant' => $tenant instanceof Tenant ? [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status->value,
                'statusLabel' => $tenant->status->label(),
                'readOnly' => $tenant->isReadOnly(),
                'idleTimeoutMinutes' => $tenant->idleTimeoutMinutes(),
            ] : null,

            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Permission dikirim ke frontend HANYA untuk menyembunyikan menu. Setiap
     * aksi tetap diperiksa ulang di server; menyembunyikan tombol bukan
     * otorisasi.
     *
     * @return array<string, mixed>
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['user' => null, 'permissions' => [], 'roles' => []];
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'roles' => $user->getRoleNames()->map(fn (string $role) => [
                'name' => $role,
                'label' => PermissionCatalog::roleLabel($role),
            ])->values(),
        ];
    }
}
