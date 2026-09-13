<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migrasi terpisah, bukan menyunting migrasi `users` yang sudah ada.
 *
 * Belum ada tenant produksi, jadi menyunting pun aman hari ini — tapi pola
 * *expand* (tambah kolom nullable di migrasi baru) adalah satu-satunya pola
 * yang aman begitu ada tenant nyata (PRD §5.4 poin 1). Lebih murah
 * membiasakannya sejak migrasi kedua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            // Diisi saat undangan diterima. Null berarti akun belum pernah
            // diaktifkan pemiliknya — password yang tersimpan adalah acak.
            $table->timestamp('activated_at')->nullable()->after('last_login_at');
        });

        // Token undangan dipisah dari token reset password. Kalau berbagi
        // tabel dan broker, token reset (berlaku 60 menit) bisa dipakai lewat
        // jalur undangan (berlaku 72 jam) — memperpanjang umurnya diam-diam.
        Schema::create('user_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitation_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'activated_at']);
        });
    }
};
