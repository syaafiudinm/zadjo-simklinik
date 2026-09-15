<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use LogicException;
use Stancl\Tenancy\DatabaseConfig;

/**
 * Dua koreksi atas `DatabaseConfig` bawaan:
 *
 * 1. Bawaan menyalin SEMUA atribut berawalan `db_` ke konfigurasi koneksi,
 *    termasuk yang null. `db_host` kosong akan menimpa host template dengan
 *    null. Di sini nilai null untuk host/port dibuang: kosong berarti "pakai
 *    bawaan template".
 *
 * 2. Tetapi username/password TIDAK boleh jatuh ke bawaan template. Template
 *    koneksi tenant adalah `tenancy_admin` — tenant tanpa user MySQL sendiri
 *    akan diam-diam berjalan dengan hak admin server, membaca database semua
 *    klinik. Koneksi seperti itu ditolak.
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

    public function connection(): array
    {
        $config = $this->tenantConfig();

        if (empty($config['username']) || empty($config['password'])) {
            throw new LogicException(
                "Tenant [{$this->tenant->getTenantKey()}] tidak punya user MySQL sendiri. Koneksi tenant tidak pernah memakai kredensial admin."
            );
        }

        return parent::connection();
    }
}
