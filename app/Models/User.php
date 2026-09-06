<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Pengguna klinik.
 *
 * Tabelnya hidup di database TENANT, bukan pusat (lihat
 * database/migrations/tenant/). Konsekuensinya: dua klinik boleh punya user
 * dengan email yang sama, dan tidak ada satu pun query yang bisa menjangkau
 * user klinik lain — isolasinya struktural, bukan hasil `where tenant_id = ?`
 * yang bisa terlupa di satu query.
 *
 * Role & permission menyusul di S1-06.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
