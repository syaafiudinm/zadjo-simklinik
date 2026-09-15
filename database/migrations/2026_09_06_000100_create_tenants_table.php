<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry tenant di database pusat.
 *
 * Tabel ini tidak pernah memuat data klinis. Isinya identitas fasyankes,
 * status langganan, dan koordinat database miliknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Slug = subdomain. 63 karakter adalah batas satu label DNS.
            $table->string('slug', 63)->unique();
            $table->string('name');

            $table->string('status', 20)->default('provisioning')->index();
            $table->string('plan', 32)->nullable();

            // Koordinat database tenant.
            //
            // `db_host` dan `db_port` disimpan sejak sekarang walaupun semua
            // tenant masih berbagi satu server — inilah yang nanti memungkinkan
            // memindahkan tenant besar ke server DB terpisah tanpa mengubah
            // kode (PRD §5.4 poin 3).
            //
            // Awalan `db_` bukan gaya penamaan bebas: `stancl/tenancy` membaca
            // kolom berawalan `db_` dan memetakannya langsung ke config koneksi
            // PDO. Lihat App\Models\Tenant::internalPrefix().
            $table->string('db_connection', 32)->nullable();
            $table->string('db_host')->nullable();
            $table->unsignedSmallInteger('db_port')->nullable();
            $table->string('db_name')->nullable();
            $table->string('db_username')->nullable();
            // Ciphertext Laravel jauh lebih panjang dari plaintext-nya.
            $table->text('db_password')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            // Kolom luapan untuk atribut yang belum layak jadi kolom sendiri.
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
