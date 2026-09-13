<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantExporter;
use Illuminate\Console\Command;
use Throwable;

class DeleteTenant extends Command
{
    protected $signature = 'tenant:delete
        {slug : Slug klinik yang akan dihapus}
        {--force : Lewati konfirmasi interaktif. Ekspor TETAP dijalankan.}';

    protected $description = 'Menghapus klinik beserta database dan user MySQL-nya, setelah mengekspor seluruh datanya.';

    public function handle(TenantExporter $exporter): int
    {
        $slug = (string) $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->components->error("Tidak ada klinik dengan slug [{$slug}].");

            return self::FAILURE;
        }

        $this->summarize($tenant);

        if (! $this->option('force') && ! $this->confirmTwice($tenant)) {
            $this->components->info('Dibatalkan. Tidak ada yang dihapus.');

            return self::FAILURE;
        }

        // Ekspor bukan opsional dan tidak ada flag untuk melewatinya. Permenkes
        // 24/2022 mewajibkan retensi rekam medis 25 tahun; menghapus klinik
        // tanpa arsip berarti menghapus kewajiban hukum klinik itu.
        try {
            $path = $exporter->export($tenant);
        } catch (Throwable $e) {
            $this->components->error('Ekspor gagal, penghapusan dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Arsip ekspor', $path);

        // Event TenantDeleted menghapus database dan user MySQL-nya.
        $tenant->delete();

        $this->components->info("Klinik {$tenant->name} telah dihapus.");
        $this->components->warn('Serahkan arsip ekspor ke klinik atau simpan sesuai kebijakan retensi (FR-M22.5).');

        return self::SUCCESS;
    }

    private function summarize(Tenant $tenant): void
    {
        $databaseExists = $tenant->database()->manager()->databaseExists($tenant->database()->getName());

        $this->components->twoColumnDetail('Klinik', $tenant->name);
        $this->components->twoColumnDetail('Status', $tenant->status->label());
        $this->components->twoColumnDetail('Database', $databaseExists ? (string) $tenant->db_name : '(belum dibuat)');

        if ($databaseExists) {
            try {
                $this->components->twoColumnDetail('Pengguna', (string) $tenant->run(fn () => User::count()));
            } catch (Throwable) {
                $this->components->twoColumnDetail('Pengguna', '(tidak terbaca)');
            }
        }

        $this->newLine();
    }

    private function confirmTwice(Tenant $tenant): bool
    {
        if (! $this->confirm("Hapus PERMANEN klinik {$tenant->name} beserta seluruh databasenya?", false)) {
            return false;
        }

        $typed = $this->ask("Ketik slug klinik ({$tenant->slug}) untuk mengonfirmasi");

        if ($typed !== $tenant->slug) {
            $this->components->error('Slug tidak cocok.');

            return false;
        }

        return true;
    }
}
