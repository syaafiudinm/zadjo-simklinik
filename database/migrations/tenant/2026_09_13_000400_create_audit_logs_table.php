<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit rekam medis elektronik (Permenkes 24/2022; PRD M22).
 *
 * Append-only. User MySQL runtime hanya memegang SELECT dan INSERT atas tabel
 * ini (config/simklinik.php → database_grants), sehingga baris audit tidak
 * bisa diubah atau dihapus dari dalam aplikasi — termasuk lewat tinker, dan
 * termasuk oleh admin klinik.
 *
 * Tidak ada foreign key ke `users`. Jejak audit harus bertahan lebih lama
 * dari apa pun yang dirujuknya, dan label aktor/subjek disalin saat kejadian
 * supaya tetap terbaca walaupun nama atau email berubah kemudian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Mikrodetik: urutan kejadian dalam satu request harus bisa dibedakan.
            $table->timestamp('occurred_at', 6)->useCurrent();
            $table->string('event', 40);

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->string('auditable_type', 100)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->string('auditable_label')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();

            $table->string('channel', 16);
            $table->uuid('request_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('context')->nullable();

            // Pertanyaan saat sengketa: "siapa saja yang membuka rekam medis X?"
            $table->index(['auditable_type', 'auditable_id', 'occurred_at']);
            // "Apa saja yang dilakukan petugas Y minggu lalu?"
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            $table->index('occurred_at');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
