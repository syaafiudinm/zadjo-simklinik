<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\TenantAdminInvitation;
use Illuminate\Support\Facades\Password;

/**
 * Mengirim undangan aktivasi akun.
 *
 * Token dibuat DI DALAM worker, bukan oleh pemanggil. Kalau token ikut di
 * properti job, ia tersimpan di payload Redis — dan Horizon menampilkan
 * payload job di dashboard-nya. Operator vendor yang membuka Horizon akan
 * bisa mengaktifkan akun admin klinik mana pun, padahal vendor tidak boleh
 * punya akses ke data klinis (PRD §4.2). Payload job ini hanya berisi
 * identitas user.
 */
class SendUserInvitation extends TenantAwareJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public User $user) {}

    public function handleForTenant(): void
    {
        $token = Password::broker('invitations')->createToken($this->user);

        // notifyNow: job ini sudah asinkron; notifikasi ter-queue kedua hanya
        // akan membawa token ke payload antrian — persis yang dihindari.
        $this->user->notifyNow(new TenantAdminInvitation(tenant(), $token));
    }
}
