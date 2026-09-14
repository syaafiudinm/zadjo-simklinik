<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Events\TenantProvisioned;
use App\Events\TenantProvisioningStepCompleted;
use App\Exceptions\TenantProvisioningException;
use App\Jobs\ProvisionTenant;
use App\Models\Domain;
use App\Models\Polyclinic;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminInvitation;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| S1-04 — Provisioning tenant otomatis
|--------------------------------------------------------------------------
*/

/** Koneksi berhak admin server — koneksi pusat sengaja tidak bisa melihat database tenant. */
it('membawa tenant dari satu perintah sampai siap login', function () {
    Notification::fake();

    $this->artisan('tenant:create', [
        'slug' => 'klinik-melati',
        'name' => 'Klinik Melati',
        'admin_email' => 'Pak.Hendra@Melati.test',
        '--sync' => true,
    ])->assertSuccessful();

    $tenant = Tenant::where('slug', 'klinik-melati')->firstOrFail();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->activated_at)->not->toBeNull()
        ->and($tenant->provisioning_step)->toBeNull()
        ->and(Domain::where('domain', 'klinik-melati')->exists())->toBeTrue()
        ->and(databaseExists($tenant->db_name))->toBeTrue()
        ->and(mysqlUserExists($tenant->db_username))->toBeTrue();

    $tenant->run(function () {
        $admin = User::where('email', 'pak.hendra@melati.test')->firstOrFail();

        expect($admin->hasRole('clinic_admin'))->toBeTrue()
            ->and($admin->activated_at)->toBeNull()
            ->and(Role::pluck('name')->sort()->values()->all())->toBe(collect(PermissionCatalog::roles())->sort()->values()->all())
            ->and(Polyclinic::pluck('code')->sort()->values()->all())->toBe(['GIGI', 'KIA', 'UMUM'])
            ->and(DB::table('user_invitation_tokens')->where('email', 'pak.hendra@melati.test')->exists())->toBeTrue();
    });

    Notification::assertSentTimes(TenantAdminInvitation::class, 1);
});

it('mengirim undangan yang tautannya menunjuk subdomain klinik itu sendiri', function () {
    Notification::fake();

    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);

    Notification::assertSentTo($admin, TenantAdminInvitation::class, function (TenantAdminInvitation $notification) use ($admin, $tenant) {
        $url = $tenant->run(fn () => $notification->toMail($admin)->actionUrl);

        return str_starts_with($url, $this->tenantUrl($tenant, '/invitation/'));
    });
});

it('membongkar semua yang sudah dibuat kalau provisioning gagal di langkah mana pun', function (string $failingStep) {
    Notification::fake();

    Event::listen(TenantProvisioningStepCompleted::class, function (TenantProvisioningStepCompleted $event) use ($failingStep) {
        if ($event->step === $failingStep) {
            throw new RuntimeException("Kegagalan buatan setelah {$failingStep}");
        }
    });

    $provisioner = app(TenantProvisioner::class);
    $tenant = $provisioner->register('klinik-gagal', 'Klinik Gagal', 'admin@gagal.test');

    // Nama database dan user MySQL baru diketahui setelah langkah pertama,
    // jadi ditangkap lewat event sebelum rollback menghapus barisnya.
    $credentials = [];
    Event::listen(TenantProvisioningStepCompleted::class, function (TenantProvisioningStepCompleted $event) use (&$credentials) {
        $credentials = ['db' => $event->tenant->db_name, 'user' => $event->tenant->db_username];
    });

    try {
        $provisioner->provision($tenant);
        $this->fail('Provisioning seharusnya gagal.');
    } catch (TenantProvisioningException $e) {
        expect($e->step)->toBe($failingStep);
    }

    expect(Tenant::where('slug', 'klinik-gagal')->exists())->toBeFalse()
        ->and(Domain::where('domain', 'klinik-gagal')->exists())->toBeFalse()
        ->and(tenancy()->initialized)->toBeFalse();

    if ($credentials !== []) {
        expect(databaseExists($credentials['db']))->toBeFalse('Database tenant gagal masih tertinggal.')
            ->and(mysqlUserExists($credentials['user']))->toBeFalse('User MySQL tenant gagal masih tertinggal.');
    }

    // Slug yang sama bisa langsung dipakai lagi — tidak ada sisa yang menghalangi.
    Event::forget(TenantProvisioningStepCompleted::class);
    $this->artisan('tenant:create', ['slug' => 'klinik-gagal', 'name' => 'Klinik Gagal', 'admin_email' => 'admin@gagal.test', '--sync' => true])
        ->assertSuccessful();
})->with(['create_database', 'migrate', 'seed', 'create_admin', 'send_invitation']);

it('tidak pernah menghapus database yang bukan miliknya saat rollback', function () {
    // Database bernama sama sudah ada — sisa penghapusan yang gagal, atau
    // dibuat manual oleh seseorang. Provisioning harus gagal, dan database
    // itu harus tetap utuh.
    $name = config('tenancy.database.prefix').'klinik_bentrok';
    adminDb()->statement("CREATE DATABASE `{$name}`");
    adminDb()->statement("CREATE TABLE `{$name}`.`data_penting` (id INT)");

    try {
        $this->artisan('tenant:create', ['slug' => 'klinik-bentrok', 'name' => 'Klinik Bentrok', 'admin_email' => 'a@b.test', '--sync' => true])
            ->assertFailed();

        expect(Tenant::where('slug', 'klinik-bentrok')->exists())->toBeFalse()
            ->and(databaseExists($name))->toBeTrue()
            ->and(adminDb()->selectOne("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'data_penting'", [$name])->n)->toBe(1);
    } finally {
        adminDb()->statement("DROP DATABASE IF EXISTS `{$name}`");
    }
});

it('membersihkan tenant yang job-nya mati sebelum sempat rollback sendiri', function () {
    // Worker di-kill karena timeout: blok catch di provisioner tidak pernah
    // berjalan, dan Laravel memanggil failed() dari proses lain.
    $provisioner = app(TenantProvisioner::class);
    $tenant = $provisioner->register('klinik-timeout', 'Klinik Timeout', 'admin@timeout.test');

    // Simulasikan provisioning yang berhenti setelah database dibuat.
    $tenant->database()->makeCredentials();
    $tenant->provisioning_owns_database = true;
    $tenant->save();
    $tenant->database()->manager()->createDatabase($tenant);
    $tenant->refresh();

    expect(databaseExists($tenant->db_name))->toBeTrue();

    (new ProvisionTenant($tenant->id))->failed(new RuntimeException('timeout'));

    expect(Tenant::find($tenant->id))->toBeNull()
        ->and(databaseExists($tenant->db_name))->toBeFalse()
        ->and(mysqlUserExists($tenant->db_username))->toBeFalse();
});

it('menjalankan provisioning lewat job antrian', function () {
    $tenant = app(TenantProvisioner::class)->register('klinik-antrian', 'Klinik Antrian', 'admin@antrian.test');

    ProvisionTenant::dispatchSync($tenant->id);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('menyelesaikan provisioning jauh di bawah target tiga menit', function () {
    // FR-M23.1. Batasnya longgar karena mesin CI lambat; yang dijaga adalah
    // regresi kasar — mis. seeder yang tiba-tiba memakan puluhan detik.
    $seconds = null;
    Event::listen(TenantProvisioned::class, function (TenantProvisioned $event) use (&$seconds) {
        $seconds = $event->seconds;
    });

    $this->createTenant('klinik-cepat');

    expect($seconds)->not->toBeNull()->toBeLessThan(30.0);
});

it('menolak slug yang tidak valid, dipesan, atau sudah dipakai', function (string $slug, string $message) {
    if ($slug === 'klinik-dipakai') {
        $this->createTenant('klinik-dipakai');
    }

    $this->artisan('tenant:create', ['slug' => $slug, 'name' => 'X', 'admin_email' => 'a@b.test', '--sync' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'huruf besar' => ['Klinik-Melati', 'huruf kecil'],
    'diakhiri tanda hubung' => ['klinik-', 'huruf kecil'],
    'subdomain dipesan' => ['admin', 'aplikasi pusat'],
    'sudah dipakai' => ['klinik-dipakai', 'sudah dipakai'],
]);

it('mengekspor lalu menghapus tenant beserta database dan user MySQL-nya', function () {
    $tenant = $this->createTenant('klinik-tutup');
    $db = $tenant->db_name;
    $user = $tenant->db_username;

    $this->artisan('tenant:delete', ['slug' => 'klinik-tutup', '--force' => true])
        ->assertSuccessful();

    expect(Tenant::where('slug', 'klinik-tutup')->exists())->toBeFalse()
        ->and(databaseExists($db))->toBeFalse()
        ->and(mysqlUserExists($user))->toBeFalse();

    $archives = glob(storage_path('app/private/tenant-exports/klinik-tutup-*.zip'));
    expect($archives)->toHaveCount(1)
        ->and(fileperms($archives[0]) & 0777)->toBe(0600);

    $zip = new ZipArchive;
    $zip->open($archives[0]);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $users = $zip->getFromName('tables/users.jsonl');
    $zip->close();
    unlink($archives[0]);

    expect($manifest['tenant']['slug'])->toBe('klinik-tutup')
        ->and($manifest['tables']['users'])->toBe(1)
        ->and($manifest)->not->toHaveKey('db_password')
        ->and(json_encode($manifest))->not->toContain((string) $tenant->db_password)
        ->and($users)->toContain('admin@klinik-tutup.test');
});

it('tetap bisa menghapus tenant yang databasenya sudah hilang dari server', function () {
    // Operator menghapus database lewat klien MySQL, atau restore parsial
    // gagal. Penghapusan tenant tidak boleh macet karenanya, dan user MySQL-nya
    // tetap harus ikut dibersihkan.
    $tenant = $this->createTenant('klinik-hilang');
    adminDb()->statement("DROP DATABASE `{$tenant->db_name}`");

    $this->artisan('tenant:delete', ['slug' => 'klinik-hilang', '--force' => true])
        ->assertSuccessful();

    expect(Tenant::where('slug', 'klinik-hilang')->exists())->toBeFalse()
        ->and(mysqlUserExists($tenant->db_username))->toBeFalse();

    foreach (glob(storage_path('app/private/tenant-exports/klinik-hilang-*.zip')) as $archive) {
        unlink($archive);
    }
});

it('membatalkan penghapusan kalau slug konfirmasi salah ketik', function () {
    $this->createTenant('klinik-melati');

    $this->artisan('tenant:delete', ['slug' => 'klinik-melati'])
        ->expectsConfirmation('Hapus PERMANEN klinik Klinik klinik-melati beserta seluruh databasenya?', 'yes')
        ->expectsQuestion('Ketik slug klinik (klinik-melati) untuk mengonfirmasi', 'klinik-mawar')
        ->assertFailed();

    expect(Tenant::where('slug', 'klinik-melati')->exists())->toBeTrue();
});
