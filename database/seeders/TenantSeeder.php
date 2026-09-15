<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Tiga klinik contoh untuk pengembangan lokal.
 *
 * Dibuat lewat TenantProvisioner — jalur yang sama persis dengan
 * `tenant:create` di produksi — supaya setiap `make fresh` sekaligus menguji
 * provisioning, bukan jalan pintas yang hanya ada di lokal.
 *
 * Sengaja bukan tiga klinik aktif semua: `klinik-anggrek` hanya-baca dan
 * `klinik-kamboja` ditangguhkan, supaya jalur status di middleware terlihat
 * setiap kali aplikasi dibuka.
 */
class TenantSeeder extends Seeder
{
    /** Password akun contoh. HANYA untuk lingkungan lokal. */
    public const LOCAL_PASSWORD = 'password';

    public function run(TenantProvisioner $provisioner): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('  TenantSeeder hanya untuk lingkungan lokal. Gunakan `php artisan tenant:create`.');

            return;
        }

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

            $tenant = $provisioner->register($slug, $name, "admin@{$slug}.test");
            $provisioner->provision($tenant);

            $tenant->run(function () use ($slug) {
                // Undangan tetap terkirim ke Mailpit; password tetap ini hanya
                // supaya developer tidak perlu membuka email setiap `make fresh`.
                User::where('email', "admin@{$slug}.test")->first()
                    ->forceFill(['password' => self::LOCAL_PASSWORD, 'activated_at' => now()])
                    ->save();

                $registrar = User::create([
                    'name' => 'Rina (Pendaftaran)',
                    'email' => "registrar@{$slug}.test",
                    'password' => self::LOCAL_PASSWORD,
                ]);
                $registrar->forceFill(['activated_at' => now()])->save();
                $registrar->assignRole('registrar');
            });

            $tenant->update(['status' => $status]);

            $this->command?->info("  {$name} [{$status->value}] → http://{$tenant->primaryDomain()}:8000");
        }
    }
}
