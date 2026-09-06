<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Tiga klinik dummy untuk pengembangan lokal.
 *
 * Sengaja bukan tiga klinik aktif semua: `klinik-anggrek` dalam status
 * read-only dan `klinik-kamboja` ditangguhkan, supaya jalur penanganan status
 * di middleware ikut terlihat setiap kali developer membuka aplikasi — bukan
 * hanya saat ada yang ingat menjalankan test-nya.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $klinik = [
            ['klinik-melati', 'Klinik Melati', TenantStatus::Active],
            ['klinik-anggrek', 'Klinik Anggrek', TenantStatus::ReadOnly],
            ['klinik-kamboja', 'Klinik Kamboja', TenantStatus::Suspended],
        ];

        foreach ($klinik as [$slug, $name, $status]) {
            if (Tenant::where('slug', $slug)->exists()) {
                $this->command?->line("  Lewati {$slug} (sudah ada)");

                continue;
            }

            // Membuat Tenant memicu pipeline TenantCreated: buat database lalu
            // jalankan migrasi tenant. Lihat App\Providers\TenancyServiceProvider.
            $tenant = Tenant::create([
                'slug' => $slug,
                'name' => $name,
                'status' => $status,
                'plan' => 'pratama',
                'activated_at' => now(),
            ]);

            // Subdomain disimpan sebagai satu fragmen, bukan hostname penuh —
            // itu yang dicari resolver saat identifikasi berbasis subdomain.
            $tenant->domains()->firstOrCreate(['domain' => $slug]);

            $this->command?->info("  {$name} → http://{$tenant->primaryDomain()}:8000");
        }
    }
}
