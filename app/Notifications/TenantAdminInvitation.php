<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Undangan untuk admin pertama klinik baru.
 *
 * Tidak mengirim password. Admin menetapkan passwordnya sendiri lewat tautan
 * bertoken yang berlaku 72 jam — vendor tidak pernah tahu password admin
 * klinik, dan tidak ada password sementara yang tertinggal di kotak masuk.
 *
 * Belum di-queue: pengiriman berjalan di dalam job provisioning yang sudah
 * asinkron. Notifikasi ter-queue yang sadar-tenant menyusul di S1-08.
 */
class TenantAdminInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.accept', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $hours = (int) ceil(config('auth.passwords.invitations.expire') / 60);

        return (new MailMessage)
            ->subject("Undangan admin — {$this->tenant->name}")
            ->greeting("Halo {$notifiable->name},")
            ->line("Sistem rekam medis elektronik untuk {$this->tenant->name} sudah siap, dan Anda terdaftar sebagai admin klinik.")
            ->line('Tetapkan password Anda untuk mulai menggunakan sistem:')
            ->action('Aktifkan akun', $url)
            ->line("Tautan ini berlaku {$hours} jam dan hanya dapat dipakai sekali.")
            ->line('Jika Anda tidak merasa terkait dengan klinik ini, abaikan email ini.');
    }
}
