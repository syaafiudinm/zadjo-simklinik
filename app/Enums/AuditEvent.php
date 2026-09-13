<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditEvent: string
{
    // Perubahan data
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Amended = 'amended';

    // Akses baca rekam medis (FR-M22.1)
    case Viewed = 'viewed';

    // Autentikasi
    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case Lockout = 'lockout';
    case PasswordReset = 'password_reset';
    case InvitationAccepted = 'invitation_accepted';

    // Otorisasi & keamanan
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case AccessDenied = 'access_denied';
    case SessionRejected = 'session_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Membuat',
            self::Updated => 'Mengubah',
            self::Deleted => 'Menghapus',
            self::Amended => 'Mengamandemen',
            self::Viewed => 'Membuka',
            self::Login => 'Masuk',
            self::Logout => 'Keluar',
            self::LoginFailed => 'Gagal masuk',
            self::Lockout => 'Login dikunci',
            self::PasswordReset => 'Mengatur ulang password',
            self::InvitationAccepted => 'Menerima undangan',
            self::RoleAssigned => 'Memberi peran',
            self::RoleRevoked => 'Mencabut peran',
            self::AccessDenied => 'Akses ditolak',
            self::SessionRejected => 'Sesi asing ditolak',
        };
    }

    /** Kejadian yang layak disorot di halaman audit. */
    public function isSecuritySignal(): bool
    {
        return in_array($this, [self::LoginFailed, self::Lockout, self::AccessDenied, self::SessionRejected], true);
    }
}
