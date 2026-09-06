<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostname yang memetakan ke tenant.
 *
 * Dengan identifikasi subdomain, kolom `domain` berisi fragmen subdomain saja
 * (`klinik-melati`). Tabel terpisah — bukan satu kolom di `tenants` — supaya
 * tenant yang nanti memakai domain sendiri tidak butuh perubahan skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->uuid('tenant_id');
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
