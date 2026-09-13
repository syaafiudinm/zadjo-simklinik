<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\ClinicalModel;
use App\Models\Tenant;
use App\Support\Tenancy\TenantDatabaseGrants;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model klinis tiruan untuk menguji ClinicalModel sebelum model klinis
 * sungguhan ada (Sprint 2).
 */
class ClinicalNote extends ClinicalModel
{
    public const TABLE = 'fixture_clinical_notes';

    protected $table = self::TABLE;

    protected $fillable = ['patient_name', 'diagnosis'];

    public function auditLabel(): string
    {
        return "Catatan {$this->patient_name}";
    }

    /**
     * Membuat tabel lewat koneksi migrator (user runtime tidak punya DDL),
     * mendaftarkan kebijakan hak tanpa DELETE seperti tabel klinis
     * sungguhan, lalu menyinkronkan hak.
     */
    public static function install(Tenant $tenant): void
    {
        config(['simklinik.database_grants.tables.'.self::TABLE => ['SELECT', 'INSERT', 'UPDATE']]);

        $tenant->run(function () {
            Schema::connection('tenant_migrator')->create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->string('patient_name');
                $table->string('diagnosis');
                $table->timestamps();
            });
        });

        app(TenantDatabaseGrants::class)->sync($tenant);
    }
}
