<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Pengguna klinik.
 *
 * Tabelnya hidup di database TENANT, bukan pusat (lihat
 * database/migrations/tenant/). Konsekuensinya: dua klinik boleh punya user
 * dengan email yang sama, dan tidak ada satu pun query yang bisa menjangkau
 * user klinik lain — isolasinya struktural, bukan hasil `where tenant_id = ?`
 * yang bisa terlupa di satu query.
 *
 * @property \Carbon\Carbon|null $last_login_at
 * @property \Carbon\Carbon|null $activated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'activated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Tautan reset password harus menunjuk subdomain klinik tempat user ini
     * terdaftar — notifikasi bawaan Laravel membangun URL dari rute tanpa
     * tahu soal tenant.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
