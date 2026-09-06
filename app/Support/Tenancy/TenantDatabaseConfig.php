<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Stancl\Tenancy\DatabaseConfig;

/**
 * `DatabaseConfig` bawaan menyalin SEMUA atribut berawalan `db_` ke konfigurasi
 * koneksi PDO tenant, termasuk yang bernilai null. Akibatnya kolom yang belum
 * terisi — `db_username` dan `db_password` sebelum provisioning membuat user
 * MySQL per tenant di S1-04 — menimpa kredensial dari koneksi template dengan
 * null, dan koneksi gagal dengan pesan "Access denied for user ''".
 *
 * Di sini nilai null dibuang, sehingga kolom kosong berarti "pakai bawaan
 * koneksi template", bukan "kosongkan".
 */
class TenantDatabaseConfig extends DatabaseConfig
{
    public function tenantConfig(): array
    {
        return array_filter(
            parent::tenantConfig(),
            static fn ($value) => $value !== null
        );
    }
}
