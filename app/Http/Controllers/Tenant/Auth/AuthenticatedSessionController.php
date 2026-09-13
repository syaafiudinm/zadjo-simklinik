<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
            'canResetPassword' => true,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Cegah session fixation. `_tenant_id` dari ScopeSessionToTenant ikut
        // terbawa karena regenerate() mempertahankan isi sesi.
        $request->session()->regenerate();
        EnforceIdleTimeout::touch($request);

        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('tenant.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $redirect = redirect()->route('login');

        return $request->input('reason') === 'idle'
            ? $redirect->with('status', EnforceIdleTimeout::expiredMessage())
            : $redirect;
    }
}
