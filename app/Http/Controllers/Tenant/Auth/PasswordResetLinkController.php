<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Hasilnya sengaja diabaikan. Menjawab "email tidak terdaftar" akan
        // mengubah halaman ini menjadi alat untuk memeriksa siapa saja staf
        // sebuah klinik.
        Password::broker('users')->sendResetLink($request->only('email'));

        return back()->with('status', 'Jika email tersebut terdaftar di klinik ini, tautan untuk mengatur ulang password telah dikirim.');
    }
}
