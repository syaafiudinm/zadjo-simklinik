<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Menyinkronkan hak akses user MySQL runtime tenant ke kebijakan per tabel.
 *
 * Kenapa per tabel, bukan `GRANT … ON db.*`: MySQL tidak bisa mencabut hak di
 * level tabel yang diberikan di level database. Selama user runtime memegang
 * `DELETE ON db.*`, `REVOKE DELETE ON db.audit_logs` ditolak — dan audit trail
 * yang "append-only" hanya append-only di level aplikasi.
 *
 * Konsekuensinya user runtime TIDAK punya hak DDL sama sekali (tidak ada
 * DROP, ALTER, TRIGGER). Migrasi berjalan lewat koneksi `tenant_migrator`
 * dengan kredensial admin, dan sinkronisasi ini dipanggil setiap kali
 * migrasi tenant selesai (lihat TenancyServiceProvider) supaya tabel baru
 * langsung mendapat hak yang benar.
 *
 * Dijalankan lewat koneksi `tenancy_admin`, satu-satunya yang punya hak GRANT.
 */
final class TenantDatabaseGrants
{
    public function sync(Tenant $tenant): void
    {
        $user = $tenant->db_username;

        if ($user === null || $user === '') {
            return;
        }

        $database = $tenant->database()->getName();
        $connection = DB::connection(config('tenancy.database.admin_connection'));
        $grantee = "'{$user}'@'%'";
        $account = $this->quote($user).'@`%`';

        // Tenant yang dibuat sebelum kebijakan ini (S1-04) memegang hak level
        // database, termasuk DROP dan ALTER. Dicabut seluruhnya dulu.
        $hasSchemaGrants = $connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ?',
            [$grantee, $database]
        )->n > 0;

        if ($hasSchemaGrants) {
            $connection->statement("REVOKE ALL PRIVILEGES ON {$this->quote($database)}.* FROM {$account}");
        }

        $current = $this->currentTablePrivileges($connection, $grantee, $database);

        foreach ($this->tables($connection, $database) as $table) {
            $desired = self::privilegesFor($table);
            $held = $current[$table] ?? [];
            $target = $this->quote($database).'.'.$this->quote($table);

            if ($grant = array_values(array_diff($desired, $held))) {
                $connection->statement('GRANT '.implode(', ', $grant)." ON {$target} TO {$account}");
            }

            if ($revoke = array_values(array_diff($held, $desired))) {
                $connection->statement('REVOKE '.implode(', ', $revoke)." ON {$target} FROM {$account}");
            }
        }
    }

    /**
     * Hak user runtime untuk satu tabel, dari config/simklinik.php.
     *
     * @return list<string>
     */
    public static function privilegesFor(string $table): array
    {
        $privileges = config("simklinik.database_grants.tables.{$table}")
            ?? config('simklinik.database_grants.default');

        $privileges = array_map('strtoupper', $privileges);
        sort($privileges);

        return $privileges;
    }

    /** @return list<string> */
    private function tables(Connection $connection, string $database): array
    {
        return array_map(
            fn ($row) => $row->name,
            $connection->select(
                "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                [$database]
            )
        );
    }

    /** @return array<string, list<string>> */
    private function currentTablePrivileges(Connection $connection, string $grantee, string $database): array
    {
        $current = [];

        foreach ($connection->select(
            'SELECT TABLE_NAME AS name, PRIVILEGE_TYPE AS privilege FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ?',
            [$grantee, $database]
        ) as $row) {
            $current[$row->name][] = strtoupper($row->privilege);
        }

        return $current;
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
