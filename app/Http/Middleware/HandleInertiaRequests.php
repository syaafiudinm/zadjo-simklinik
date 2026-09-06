<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
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

            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],

            // `null` di konteks pusat. Frontend memakai ini untuk membedakan
            // landing page vendor dari aplikasi klinik, dan untuk memunculkan
            // banner hanya-baca tanpa perlu tiap halaman mengirimkannya sendiri.
            'tenant' => $tenant instanceof Tenant ? [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status->value,
                'statusLabel' => $tenant->status->label(),
                'readOnly' => $tenant->isReadOnly(),
            ] : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
