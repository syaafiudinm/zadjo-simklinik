<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi per klinik yang harus terbaca dari konteks pusat — misalnya saat
 * provisioning berjalan atau panel vendor menampilkan ringkasan tenant, yaitu
 * saat tenancy belum (atau tidak boleh) diinisialisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
