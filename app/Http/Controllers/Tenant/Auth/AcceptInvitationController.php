<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menerima undangan: pemilik akun menetapkan password pertamanya sendiri.
 *
 * Memakai broker `invitations` dengan tabel token terpisah dari reset
 * password — lihat config/auth.php.
 */
class AcceptInvitationController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'mode' => 'invitation',
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $accepted = null;

        $status = Password::broker('invitations')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$accepted) {
                $user->forceFill([
                    'password' => $request->string('password')->toString(),
                    'remember_token' => Str::random(60),
                    'activated_at' => $user->activated_at ?? now(),
                    // Menerima tautan yang dikirim ke kotak masuk adalah bukti
                    // kepemilikan alamat email.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                $accepted = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET || $accepted === null) {
            throw ValidationException::withMessages([
                'email' => 'Undangan tidak valid atau sudah kedaluwarsa. Minta admin klinik mengirim ulang undangan.',
            ]);
        }

        Auth::login($accepted);
        $request->session()->regenerate();
        EnforceIdleTimeout::touch($request);
        $accepted->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('tenant.dashboard')->with('success', 'Akun Anda sudah aktif.');
    }
}
