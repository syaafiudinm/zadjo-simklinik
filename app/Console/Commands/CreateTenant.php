<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Exceptions\TenantProvisioningException;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Rules\TenantSlug;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {slug : Subdomain klinik, mis. klinik-melati}
        {name : Nama klinik}
        {admin_email : Email admin pertama, menerima undangan}
        {--admin-name= : Nama admin (bawaan: "Admin <nama klinik>")}
        {--plan=pratama : Paket langganan}
        {--sync : Jalankan provisioning di proses ini, tanpa worker antrian}
        {--timeout=180 : Detik menunggu worker menyelesaikan provisioning}';

    protected $description = 'Membuat klinik baru: database, user MySQL, migrasi, data awal, admin, dan undangan.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $input = [
            'slug' => $this->argument('slug'),
            'name' => $this->argument('name'),
            'admin_email' => $this->argument('admin_email'),
        ];

        $validator = Validator::make($input, [
            'slug' => [new TenantSlug, Rule::unique(Tenant::class, 'slug')],
            'name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email'],
        ], ['slug.unique' => 'Slug [:input] sudah dipakai klinik lain.']);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $started = microtime(true);
        $tenant = $provisioner->register(
            $input['slug'],
            $input['name'],
            $input['admin_email'],
            $this->option('admin-name'),
            (string) $this->option('plan'),
        );

        $this->components->info("Tenant {$tenant->name} terdaftar. Memulai provisioning…");

        $ok = $this->option('sync') || config('queue.default') === 'sync'
            ? $this->runInline($provisioner, $tenant)
            : $this->dispatchAndWait($tenant);

        if (! $ok) {
            return self::FAILURE;
        }

        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $url = 'http://'.$tenant->primaryDomain().($port ? ":{$port}" : '');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Klinik siap</>', sprintf('%.1f detik', microtime(true) - $started));
        $this->components->twoColumnDetail('Alamat', $url);
        $this->components->twoColumnDetail('Admin', $input['admin_email'].' (undangan terkirim)');
        $this->components->twoColumnDetail('Database', (string) $tenant->fresh()->db_name);

        return self::SUCCESS;
    }

    private function runInline(TenantProvisioner $provisioner, Tenant $tenant): bool
    {
        try {
            $provisioner->provision($tenant);

            return true;
        } catch (TenantProvisioningException $e) {
            $this->components->error($e->getMessage());
            $this->line('  Semua yang sempat dibuat sudah dibersihkan. Perbaiki penyebabnya lalu jalankan ulang perintah ini.');

            return false;
        }
    }

    private function dispatchAndWait(Tenant $tenant): bool
    {
        ProvisionTenant::dispatch($tenant->id);

        $deadline = time() + (int) $this->option('timeout');
        $current = null;

        // Langkah ditandai selesai saat langkah BERIKUTNYA terlihat, bukan
        // saat ia sendiri terlihat — yang terbaca dari baris tenant adalah
        // langkah yang sedang berjalan. Langkah yang terlalu cepat untuk
        // tertangkap interval polling memang tidak ditampilkan.
        $finish = function (?string $step): void {
            if ($step !== null) {
                $this->components->task(TenantProvisioner::STEPS[$step] ?? $step);
            }
        };

        while (time() < $deadline) {
            $fresh = Tenant::find($tenant->id);

            if ($fresh === null) {
                $failure = Cache::pull(TenantProvisioner::failureCacheKey($tenant->slug));
                $where = isset($failure['step']) ? " pada langkah [{$failure['step']}]" : '';
                $this->components->error("Provisioning gagal{$where}: ".($failure['message'] ?? 'lihat log worker.'));
                $this->line('  Semua yang sempat dibuat sudah dibersihkan. Perbaiki penyebabnya lalu jalankan ulang perintah ini.');

                return false;
            }

            if ($fresh->status === TenantStatus::Active) {
                $finish($current === 'activate' ? null : $current);
                $finish('activate');

                return true;
            }

            $step = $fresh->provisioning_step;
            if ($step !== null && $step !== $current) {
                $finish($current);
                $current = $step;
            }

            usleep(250_000);
        }

        $this->components->warn("Provisioning belum selesai setelah {$this->option('timeout')} detik.");
        $this->line('  Pastikan worker antrian berjalan (`make dev` atau `php artisan queue:work`).');
        $this->line('  Tenant tetap berstatus provisioning dan akan dibersihkan otomatis kalau job-nya gagal.');

        return false;
    }
}
