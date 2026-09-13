<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Ekspor lengkap isi database satu tenant ke arsip ZIP.
 *
 * Format v1 sengaja "mentah tapi terbuka": skema SQL + satu berkas JSON Lines
 * per tabel. Cukup untuk dipulihkan ke MySQL mana pun atau dibaca tanpa
 * aplikasi ini. Ekspor berformat FHIR + CSV untuk hak portabilitas pasien
 * (FR-M22.8) dibangun di atasnya nanti.
 *
 * ARSIP INI BERISI DATA KESEHATAN. Berkas ditulis dengan izin 0600 di
 * direktori privat, tetapi belum terenkripsi — jangan dipindahkan keluar
 * server tanpa enkripsi.
 */
final class TenantExporter
{
    public const FORMAT_VERSION = 1;

    public function export(Tenant $tenant): string
    {
        if (tenancy()->initialized) {
            // storage_path() sudah di-suffix per tenant saat tenancy aktif;
            // arsip ekspor harus hidup di area pusat, bukan di dalam folder
            // milik tenant yang sebentar lagi dihapus.
            throw new LogicException('Ekspor tenant harus dijalankan dari konteks pusat.');
        }

        $directory = storage_path('app/private/tenant-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Tidak dapat membuat direktori ekspor {$directory}.");
        }

        $path = $directory.'/'.$tenant->slug.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.zip';
        $scratch = [];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Tidak dapat membuat arsip {$path}.");
        }

        $databaseName = $tenant->database()->getName();
        $databaseExists = $tenant->database()->manager()->databaseExists($databaseName);
        $tables = [];

        try {
            if ($databaseExists) {
                $tables = $tenant->run(fn () => $this->dumpTables($zip, $scratch));
            }

            $zip->addFromString('manifest.json', json_encode([
                'format_version' => self::FORMAT_VERSION,
                'exported_at' => now()->toIso8601String(),
                'tenant' => [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'plan' => $tenant->plan,
                    'status' => $tenant->status->value,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                ],
                // Kredensial database sengaja tidak ikut.
                'database' => ['name' => $databaseName, 'existed' => $databaseExists],
                'tables' => $tables,
                'notice' => 'Arsip ini berisi data kesehatan pribadi (UU 27/2022). Simpan terenkripsi dan batasi aksesnya.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException("Gagal menulis arsip {$path}.");
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        } finally {
            // ZipArchive baru membaca berkas sementara saat close(), jadi
            // penghapusannya harus sesudah itu.
            foreach ($scratch as $file) {
                @unlink($file);
            }
        }

        chmod($path, 0600);
        $this->verify($path, $tables);

        return $path;
    }

    /**
     * @param  list<string>  $scratch
     * @return array<string, int> nama tabel => jumlah baris
     */
    private function dumpTables(ZipArchive $zip, array &$scratch): array
    {
        $connection = DB::connection();
        $counts = [];
        $schema = '';

        $tables = array_map(
            fn ($row) => array_values((array) $row)[0],
            $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        );
        sort($tables);

        foreach ($tables as $table) {
            $create = (array) $connection->selectOne("SHOW CREATE TABLE `{$table}`");
            $schema .= ($create['Create Table'] ?? '').";\n\n";

            $file = tempnam(sys_get_temp_dir(), 'tenant-export-');
            $scratch[] = $file;
            $handle = fopen($file, 'wb');
            $count = 0;

            foreach ($connection->table($table)->cursor() as $row) {
                fwrite($handle, json_encode($this->normalize((array) $row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $count++;
            }

            fclose($handle);
            $zip->addFile($file, "tables/{$table}.jsonl");
            $counts[$table] = $count;
        }

        $zip->addFromString('schema.sql', $schema);

        return $counts;
    }

    /**
     * Kolom biner (UUID v7 BINARY(16) di tabel klinis Sprint 2) bukan UTF-8
     * yang sah dan akan membuat json_encode gagal. Dibungkus base64 dengan
     * penanda supaya bisa dikembalikan persis.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = ['$binary' => base64_encode($value)];
            }
        }

        return $row;
    }

    /** @param  array<string, int>  $tables */
    private function verify(string $path, array $tables): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Arsip ekspor {$path} tidak dapat dibuka ulang.");
        }

        foreach (array_keys($tables) as $table) {
            if ($zip->locateName("tables/{$table}.jsonl") === false) {
                $zip->close();
                throw new RuntimeException("Arsip ekspor tidak memuat tabel {$table}.");
            }
        }

        $zip->close();
    }
}
