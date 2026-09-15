<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Selalu dijadwalkan, terdaftar atau tidak: jawaban DAN waktu respons
        // yang sama untuk semua email, supaya halaman ini tidak bisa dipakai
        // memeriksa siapa saja staf sebuah klinik. Lihat SendPasswordResetLink.
        SendPasswordResetLink::dispatch($request->string('email')->lower()->toString());

        return back()->with('status', 'Jika email tersebut terdaftar di klinik ini, tautan untuk mengatur ulang password telah dikirim.');
    }
}
