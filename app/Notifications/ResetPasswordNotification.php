<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reset password yang tautannya menunjuk subdomain klinik.
 *
 * Bawaan Laravel membangun URL lewat `url(route(..., absolute: false))`, yang
 * bergantung pada host request saat itu. Di sini URL dibangun absolut dari
 * rute tenant, sehingga tetap benar walaupun notifikasi dikirim dari worker
 * antrian yang tidak punya request.
 */
class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Atur ulang password')
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
            ->action('Atur ulang password', $this->resetUrl($notifiable))
            ->line("Tautan ini berlaku {$minutes} menit.")
            ->line('Jika Anda tidak meminta pengaturan ulang, abaikan email ini — password Anda tidak berubah.');
    }
}
