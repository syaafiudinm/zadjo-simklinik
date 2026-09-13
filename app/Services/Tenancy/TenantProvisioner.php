<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Events\TenantProvisioned;
use App\Events\TenantProvisioningStepCompleted;
use App\Exceptions\TenantProvisioningException;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminInvitation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Stancl\Tenancy\Database\DatabaseManager;
use Throwable;

/**
 * Membawa tenant dari baris registry sampai klinik siap login.
 *
 * Dua tahap, sengaja dipisah:
 *
 *  1. register()  — sinkron dan murah: menulis baris tenant berstatus
 *                   `provisioning` dan mengunci slug-nya. Gagal di sini berarti
 *                   input salah, bukan infrastruktur.
 *  2. provision() — lambat, dijalankan di job antrian: database, user MySQL,
 *                   migrasi, seed, admin, undangan, aktivasi.
 *
 * Kalau satu langkah di tahap 2 gagal, semua yang sudah dibuat dibongkar dan
 * baris tenant dihapus. Tidak ada tenant setengah jadi: sebuah slug selalu
 * berarti "klinik yang berfungsi" atau "belum pernah ada".
 */
final class TenantProvisioner
{
    /** @var array<string, string> Urutan langkah beserta labelnya untuk log dan CLI. */
    public const STEPS = [
        'create_database' => 'Membuat database & user MySQL',
        'migrate' => 'Menjalankan migrasi tenant',
        'seed' => 'Menyemai role, permission, dan poli default',
        'create_admin' => 'Membuat akun admin klinik',
        'send_invitation' => 'Mengirim email undangan',
        'activate' => 'Mengaktifkan tenant',
    ];

    public function __construct(private readonly DatabaseManager $databases) {}

    public function register(
        string $slug,
        string $name,
        string $adminEmail,
        ?string $adminName = null,
        string $plan = 'pratama',
    ): Tenant {
        return DB::connection(config('tenancy.database.central_connection'))
            ->transaction(function () use ($slug, $name, $adminEmail, $adminName, $plan) {
                $tenant = Tenant::create([
                    'slug' => $slug,
                    'name' => $name,
                    'status' => TenantStatus::Provisioning,
                    'plan' => $plan,
                    // Disimpan di kolom JSON `data`: hanya dibutuhkan selama
                    // provisioning, dan tidak layak menjadi kolom registry.
                    'admin_email' => Str::lower($adminEmail),
                    'admin_name' => $adminName ?? 'Admin '.$name,
                ]);

                $tenant->domains()->create(['domain' => $slug]);

                return $tenant;
            });
    }

    public function provision(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Provisioning) {
            throw new LogicException("Tenant [{$tenant->slug}] berstatus {$tenant->status->value}, bukan provisioning.");
        }

        $started = microtime(true);
        $step = null;

        try {
            $step = $this->begin($tenant, 'create_database');
            $tenant->database()->makeCredentials();
            // Pemeriksaan ini WAJIB mendahului penandaan kepemilikan. Kalau
            // database dengan nama sama sudah ada — sisa penghapusan yang
            // gagal, atau database yang dibuat manual — ia BUKAN milik kita,
            // dan rollback tidak boleh menghapusnya.
            $this->databases->ensureTenantCanBeCreated($tenant);
            $this->remember($tenant, 'provisioning_owns_database', true);
            $tenant->database()->manager()->createDatabase($tenant);
            $this->complete($tenant, $step);

            $step = $this->begin($tenant, 'migrate');
            $this->artisan('tenants:migrate', $tenant);
            $this->complete($tenant, $step);

            $step = $this->begin($tenant, 'seed');
            $this->artisan('tenants:seed', $tenant);
            $this->complete($tenant, $step);

            $step = $this->begin($tenant, 'create_admin');
            [$admin, $token] = $tenant->run(function () use ($tenant) {
                $admin = User::create([
                    'name' => $tenant->admin_name,
                    'email' => $tenant->admin_email,
                    // Tidak pernah diketahui siapa pun. Admin menetapkan
                    // password sendiri lewat tautan undangan.
                    'password' => Str::password(64),
                ]);
                $admin->assignRole('clinic_admin');

                return [$admin, Password::broker('invitations')->createToken($admin)];
            });
            $this->complete($tenant, $step);

            $step = $this->begin($tenant, 'send_invitation');
            $tenant->run(fn () => $admin->notify(new TenantAdminInvitation($tenant, $token)));
            $this->complete($tenant, $step);

            $step = $this->begin($tenant, 'activate');
            $tenant->status = TenantStatus::Active;
            $tenant->activated_at = now();
            unset($tenant->provisioning_step, $tenant->provisioning_owns_database);
            $tenant->save();
            $this->complete($tenant, $step);
        } catch (Throwable $e) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $this->abandon($tenant);

            $failure = new TenantProvisioningException($tenant->slug, $step, $e);

            // Dibaca `tenant:create` yang sedang menunggu di proses lain: baris
            // tenant sudah dihapus, jadi pesan kegagalannya perlu tempat lain.
            Cache::put(self::failureCacheKey($tenant->slug), [
                'step' => $step,
                'message' => $e->getMessage(),
            ], now()->addMinutes(10));

            throw $failure;
        }

        event(new TenantProvisioned($tenant, microtime(true) - $started));
    }

    /**
     * Membongkar tenant yang provisioningnya tidak selesai.
     *
     * Aman dipanggil berkali-kali dan dari proses lain (mis. `failed()` pada
     * job yang di-kill karena timeout, saat blok catch di atas tidak pernah
     * sempat berjalan) — kepemilikan database dibaca dari baris tenant, bukan
     * dari variabel lokal.
     */
    public function abandon(Tenant $tenant): void
    {
        $tenant = Tenant::find($tenant->getTenantKey());

        if (! $tenant || $tenant->status !== TenantStatus::Provisioning) {
            return;
        }

        if ($tenant->provisioning_owns_database === true) {
            try {
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (Throwable $e) {
                // Jangan telan diam-diam: database atau user MySQL yatim adalah
                // sampah yang harus dibersihkan tangan.
                Log::critical('Rollback provisioning gagal menghapus database tenant.', [
                    'tenant' => $tenant->slug,
                    'database' => $tenant->db_name,
                    'db_user' => $tenant->db_username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Tanpa event model: pipeline TenantDeleted akan mencoba menghapus
        // database lagi — termasuk database yang BUKAN milik kita kalau
        // kegagalannya justru karena nama database sudah terpakai.
        Tenant::withoutEvents(fn () => $tenant->delete());

        Log::warning('Provisioning tenant dibatalkan dan dibersihkan.', [
            'tenant' => $tenant->slug,
            'step' => $tenant->provisioning_step,
        ]);
    }

    public static function failureCacheKey(string $slug): string
    {
        return "tenant-provisioning-failure:{$slug}";
    }

    private function begin(Tenant $tenant, string $step): string
    {
        $this->remember($tenant, 'provisioning_step', $step);

        return $step;
    }

    private function complete(Tenant $tenant, string $step): void
    {
        event(new TenantProvisioningStepCompleted($tenant, $step));
    }

    private function remember(Tenant $tenant, string $key, mixed $value): void
    {
        $tenant->setAttribute($key, $value);
        $tenant->save();
    }

    private function artisan(string $command, Tenant $tenant): void
    {
        $exit = Artisan::call($command, [
            '--tenants' => [$tenant->getTenantKey()],
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException("{$command} keluar dengan kode {$exit}: ".trim(Artisan::output()));
        }
    }
}
