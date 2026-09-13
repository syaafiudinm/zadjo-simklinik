<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poli — unit layanan di dalam klinik.
 *
 * Masuk sekarang karena provisioning (S1-04) menyemai poli default, dan
 * prefix antrian per poli (FR-M2.2) butuh tempat sejak hari pertama. Pemetaan
 * ke `Location` SATUSEHAT (FR-M0.3) menyusul lewat kolom tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polyclinics', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            // Satu huruf atau lebih di depan nomor antrian: A-001, G-014.
            $table->string('queue_prefix', 4)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polyclinics');
    }
};
