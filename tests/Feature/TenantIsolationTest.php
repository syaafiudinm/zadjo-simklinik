<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Exceptions\TenantContextMismatchException;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\Polyclinic;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Jobs\RecordCentralContext;
use Tests\Fixtures\Jobs\RenamePolyclinic;

/*
|--------------------------------------------------------------------------
| Suite isolasi tenant — S1-09
|--------------------------------------------------------------------------
|
| BERKAS INI ADALAH ASET PALING BERHARGA DI REPO.
|
| Satu tempat yang membuktikan bahwa data satu klinik tidak pernah terlihat,
| tertimpa, atau tersentuh dari klinik lain — atau dari konteks pusat. Suite
| ini dijalankan terpisah di CI sebagai gerbang wajib sebelum merge
| (`make test-isolation`, grup `isolation`).
|
| Aturannya: setiap kali ditemukan celah isolasi baru, tulis test-nya DI SINI
| dulu — pastikan test itu gagal — baru perbaiki kodenya.
|
| Tujuh butir pertama mengikuti Sprint 1 §S1-09. Butir 8 berisi celah yang
| pernah benar-benar ditemukan saat membangun sistem ini.
|
*/

uses()->group('isolation');

beforeEach(function () {
    RenamePolyclinic::$ranIn = [];
    RecordCentralContext::$tenancyWasInitialized = null;
    $this->queueName = 'test-'.Str::lower(Str::random(10));
});

afterEach(function () {
    DB::connection(config('tenancy.database.central_connection'))
        ->table('failed_jobs')->where('queue', $this->queueName)->delete();
});

// ─── 1. Data model tidak terlihat dari tenant lain ───────────────────
//
// Setiap klinik punya database dan user MySQL sendiri. Pertahanan lapis
// kedua — grant MySQL — tetap menahan kalau koneksi aplikasi salah konfigurasi.

it('tidak menampilkan baris tenant lain dari konteks tenant manapun', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(function () {
        User::factory()->create(['email' => 'perawat@klinik-a.test']);
    });

    $b->run(function () {
        User::factory()->create(['email' => 'perawat@klinik-b.test']);
        User::factory()->create(['email' => 'apoteker@klinik-b.test']);
    });

    // Setiap tenant sudah punya satu admin dari provisioning.
    $a->run(function () {
        expect(User::count())->toBe(2)
            ->and(User::where('email', 'perawat@klinik-b.test')->exists())->toBeFalse()
            ->and(User::where('email', 'admin@klinik-b.test')->exists())->toBeFalse();
    });

    $b->run(function () {
        expect(User::count())->toBe(3)
            ->and(User::where('email', 'perawat@klinik-a.test')->exists())->toBeFalse()
            ->and(User::where('email', 'admin@klinik-a.test')->exists())->toBeFalse();
    });
});

it('mengizinkan email yang sama di dua klinik berbeda', function () {
    // Konsekuensi langsung dari database-per-tenant: seorang dokter yang praktik
    // di dua klinik punya dua akun terpisah, dan tidak ada unique constraint
    // lintas klinik yang bisa menghalanginya.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(fn () => User::factory()->create(['email' => 'dr.aditya@contoh.test']));
    $b->run(fn () => User::factory()->create(['email' => 'dr.aditya@contoh.test']));

    $a->run(fn () => expect(User::where('email', 'dr.aditya@contoh.test')->count())->toBe(1));
    $b->run(fn () => expect(User::where('email', 'dr.aditya@contoh.test')->count())->toBe(1));
});

it('memberi setiap tenant database dan user MySQL fisik yang berbeda', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    expect($a->db_name)->not->toBe($b->db_name)
        ->and($a->db_username)->not->toBe($b->db_username)
        ->and(databaseExists($a->db_name))->toBeTrue()
        ->and(databaseExists($b->db_name))->toBeTrue();

    $a->run(fn () => expect(DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS u'))
        ->db->toBe($a->db_name)
        ->u->toStartWith($a->db_username.'@'));
});

it('menolak user MySQL satu tenant membaca database tenant lain di level MySQL', function () {
    // Pertahanan lapis kedua. Kalau suatu hari koneksi aplikasi salah
    // dikonfigurasi, grant MySQL tetap menolak.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $port = config('database.connections.mysql.port');
    $pdo = new PDO("mysql:host=127.0.0.1;port={$port}", $a->db_username, $a->db_password);

    expect((int) $pdo->query("SELECT COUNT(*) FROM `{$a->db_name}`.users")->fetchColumn())->toBe(1);

    expect(fn () => $pdo->query("SELECT COUNT(*) FROM `{$b->db_name}`.users"))
        ->toThrow(PDOException::class, 'denied');
});

// ─── 2. Sesi dan login tidak menyeberang ─────────────────────────────
//
// Kedua admin ber-id 1 di databasenya masing-masing — skenario di mana sesi
// yang bocor berarti masuk sebagai orang lain di klinik lain.

it('menolak kredensial tenant A di subdomain tenant B', function () {
    // Skrip demo sprint langkah 6.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    setPassword($a, 'admin@klinik-a.test', PASSWORD_A);

    $this->post($this->tenantUrl($b, '/login'), ['email' => 'admin@klinik-a.test', 'password' => PASSWORD_A])
        ->assertSessionHasErrors(['email' => 'Email atau password tidak sesuai.']);

    $this->assertGuest();
});

it('tidak menerima cookie sesi tenant A di subdomain tenant B', function () {
    // Skenario kebocoran klasik: kedua admin ber-id 1 di databasenya masing-
    // masing. Kalau sesi A diterima di B, "user id 1" akan di-resolve dari
    // database B — penyerang masuk sebagai admin klinik lain.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    setPassword($a, 'admin@klinik-a.test', PASSWORD_A);

    expect($this->adminOf($a)->id)->toBe($this->adminOf($b)->id);

    $cookie = loginVia($this, $a, 'admin@klinik-a.test', PASSWORD_A);

    // Kontrol positif: cookie yang sama di A memang membawa sesi yang valid.
    // Tanpa ini, test di bawah bisa lolos hanya karena cookie-nya rusak.
    asBrowser($this, $cookie)->get($this->tenantUrl($a, '/'))->assertOk();

    // Diperlakukan sebagai tamu biasa: diarahkan ke login klinik B.
    asBrowser($this, $cookie)->get($this->tenantUrl($b, '/'))
        ->assertRedirect($this->tenantUrl($b, '/login'));
    $this->assertGuest();

    // Regresi yang pernah terjadi: sesi asing ditolak 403, tapi halaman 403
    // tetap me-resolve user dari sesi itu dan mengirim identitas admin B ke
    // pemegang cookie A lewat props Inertia. Halaman apa pun yang tampil
    // tidak boleh memuat user.
    asBrowser($this, $cookie)->get($this->tenantUrl($b, '/login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));

    // Cookie curian itu kini hangus, juga di klinik asalnya.
    asBrowser($this, $cookie)->get($this->tenantUrl($a, '/'))
        ->assertRedirect($this->tenantUrl($a, '/login'));
});

// ─── 3. Cache tidak bertabrakan ──────────────────────────────────────
//
//

it('tidak menabrakkan kunci cache antar tenant', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(fn () => cache()->put('antrian-berikutnya', 'A-001', 60));
    $b->run(fn () => cache()->put('antrian-berikutnya', 'B-042', 60));

    $a->run(fn () => expect(cache()->get('antrian-berikutnya'))->toBe('A-001'));
    $b->run(fn () => expect(cache()->get('antrian-berikutnya'))->toBe('B-042'));

    // Dan konteks pusat tidak melihat keduanya.
    expect(cache()->get('antrian-berikutnya'))->toBeNull();
});

// ─── 4. Job dieksekusi di konteks tenant yang benar ──────────────────
//
// Risiko kritis di tabel risiko PRD: job SATUSEHAT klinik A yang berjalan
// dengan konteks — dan kredensial — klinik B.

it('menjalankan setiap job di database tenant pemiliknya, apa pun konteks terakhir worker', function () {
    // TEST KRITIS S1-08. Risiko kritis di tabel risiko PRD: job SATUSEHAT
    // klinik A yang berjalan dengan konteks klinik B.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $umumA = umumOf($a);
    $umumB = umumOf($b);

    // Jebakannya: id-nya sama di kedua database. Job yang berjalan di konteks
    // salah tidak gagal — ia diam-diam mengubah poli klinik lain.
    expect($umumA->id)->toBe($umumB->id);

    $queue = $this->queueName;
    $a->run(function () use ($umumA, $queue) {
        Context::add('request_id', 'req-klinik-a');
        RenamePolyclinic::dispatch($umumA, 'Poli Umum A (1)')->onConnection('redis')->onQueue($queue);
    });
    $b->run(function () use ($umumB, $queue) {
        RenamePolyclinic::dispatch($umumB, 'Poli Umum B')->onConnection('redis')->onQueue($queue);
    });
    $a->run(function () use ($umumA, $queue) {
        RenamePolyclinic::dispatch($umumA, 'Poli Umum A (2)')->onConnection('redis')->onQueue($queue);
    });
    Context::forget('request_id');
    RecordCentralContext::dispatch()->onConnection('redis')->onQueue($queue);

    // Worker yang "terakhir" bekerja untuk klinik B.
    tenancy()->initialize($b);

    runWorker($this);

    if (tenancy()->initialized) {
        tenancy()->end();
    }

    expect(failedJobs($queue))->toBeEmpty()
        ->and(RenamePolyclinic::$ranIn)->toBe(['klinik-a', 'klinik-b', 'klinik-a'])
        // Job pusat sesudahnya tidak mewarisi konteks tenant mana pun.
        ->and(RecordCentralContext::$tenancyWasInitialized)->toBeFalse();

    $a->run(function () {
        expect(Polyclinic::where('code', 'UMUM')->value('name'))->toBe('Poli Umum A (2)');

        $audit = AuditLog::where('event', AuditEvent::Updated)->where('auditable_type', Polyclinic::class)->orderBy('id')->get();
        expect($audit)->toHaveCount(2)
            ->and($audit->pluck('channel')->unique()->all())->toBe(['queue'])
            // Id request pemicu ikut terbawa ke worker lewat Context.
            ->and($audit->first()->request_id)->toBe('req-klinik-a');
    });

    $b->run(function () {
        expect(Polyclinic::where('code', 'UMUM')->value('name'))->toBe('Poli Umum B')
            ->and(AuditLog::where('event', AuditEvent::Updated)->where('auditable_type', Polyclinic::class)->count())->toBe(1);
    });
});

it('menggagalkan job tanpa efek samping kalau konteks worker tidak cocok dengan pemiliknya', function () {
    // Penjaga lapis terakhir: kalau inisialisasi tenancy di worker suatu hari
    // rusak, job berhenti keras alih-alih menulis ke database yang aktif.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $umumB = umumOf($b);
    $queue = $this->queueName;

    $b->run(function () use ($a, $umumB, $queue) {
        $job = new RenamePolyclinic($umumB, 'Dibajak');
        $job->tenantId = (string) $a->id;
        dispatch($job)->onConnection('redis')->onQueue($queue);
    });

    runWorker($this);

    expect(RenamePolyclinic::$ranIn)->toBe([])
        ->and(failedJobs($queue))->toHaveCount(1)
        ->and(failedJobs($queue)->first()->exception)->toContain(TenantContextMismatchException::class);

    $b->run(fn () => expect(Polyclinic::where('code', 'UMUM')->value('name'))->toBe('Poli Umum'));
});

// ─── 5. Berkas tersimpan di direktori terpisah ───────────────────────
//
//

it('menyimpan berkas unggahan di direktori terpisah per tenant', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    // Nama unik per run: berkas basi dari run yang terhenti — atau dari uji
    // mutasi yang mencabut isolasi berkas dan menulis ke storage pusat — tidak
    // boleh membuat test ini gagal atau, lebih buruk, lolos.
    $document = 'lampiran/hasil-lab-'.Str::lower(Str::random(10)).'.pdf';
    $logo = 'logo-'.Str::lower(Str::random(10)).'.png';

    try {
        [$uploaded, $physical] = $a->run(function () use ($document, $logo) {
            Storage::disk('local')->put($document, 'hasil lab milik klinik A');
            Storage::disk('public')->put($logo, 'logo klinik A');
            $path = UploadedFile::fake()->create('rontgen-thorax.pdf', 12)->store('lampiran');

            return [$path, Storage::disk('local')->path($document)];
        });

        $a->run(fn () => expect(Storage::disk('local')->exists($uploaded))->toBeTrue()
            ->and(Storage::disk('local')->get($document))->toBe('hasil lab milik klinik A'));

        // Nama berkas sama persis — klinik B tetap tidak melihat apa pun.
        $b->run(fn () => expect(Storage::disk('local')->exists($document))->toBeFalse()
            ->and(Storage::disk('local')->exists($uploaded))->toBeFalse()
            ->and(Storage::disk('public')->exists($logo))->toBeFalse()
            ->and(Storage::disk('local')->allFiles())->toBeEmpty());

        // Konteks pusat juga tidak.
        expect(Storage::disk('local')->exists($document))->toBeFalse()
            ->and(Storage::disk('public')->exists($logo))->toBeFalse();

        // Dan secara fisik berkasnya berada di bawah direktori milik tenant A.
        expect($physical)->toContain(DIRECTORY_SEPARATOR.'tenant'.$a->id.DIRECTORY_SEPARATOR)
            ->and(file_exists($physical))->toBeTrue();
    } finally {
        foreach ([$a, $b] as $tenant) {
            File::deleteDirectory(storage_path('tenant'.$tenant->id));
        }

        // Kalau isolasi berkas rusak, berkas mendarat di storage pusat.
        Storage::disk('local')->delete([$document, $uploaded ?? '']);
        Storage::disk('public')->delete($logo);
    }
});

// ─── 6. Migrasi baru terpasang di semua tenant ───────────────────────
//
// Satu codebase melayani semua klinik. Tenant yang tertinggal di skema lama
// sementara kodenya baru adalah sumber error acak yang sulit dilacak.

/** Menulis migrasi tenant sementara ke direktori sendiri dan mendaftarkannya. */
function registerTemporaryTenantMigration(string $body): string
{
    $dir = sys_get_temp_dir().'/simklinik-migrasi-'.Str::lower(Str::random(8));
    File::ensureDirectoryExists($dir);
    File::put($dir.'/2099_01_01_000000_create_isolation_probe_table.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$body}\n    }\n};\n");

    config(['tenancy.migration_parameters.--path' => [database_path('migrations/tenant'), $dir]]);

    return $dir;
}

it('memasang migrasi baru ke semua tenant, termasuk tenant yang dibuat sesudahnya', function () {
    $tenants = collect(['klinik-a', 'klinik-b', 'klinik-c'])->map(fn (string $slug) => $this->createTenant($slug));

    $dir = registerTemporaryTenantMigration(<<<'PHP'
        Schema::create('isolation_probe', function (Blueprint $table) {
            $table->id();
            $table->string('note');
        });
PHP);

    try {
        $this->artisan('tenants:migrate')->assertSuccessful();

        foreach ($tenants as $tenant) {
            // Menulis sebagai user RUNTIME: tabel baru langsung mendapat hak,
            // bukan hanya ada di skema.
            $tenant->run(function () use ($tenant) {
                DB::table('isolation_probe')->insert(['note' => $tenant->slug]);

                expect(DB::table('isolation_probe')->pluck('note')->all())->toBe([$tenant->slug]);
            });
        }

        // Tenant yang lahir setelah migrasi itu mendapat skema yang sama.
        $this->createTenant('klinik-baru')->run(fn () => expect(Schema::hasTable('isolation_probe'))->toBeTrue());

        // Migrasi tenant tidak pernah mendarat di database pusat.
        expect(Schema::connection(config('tenancy.database.central_connection'))->hasTable('isolation_probe'))->toBeFalse();
    } finally {
        File::deleteDirectory($dir);
    }
});

it('menghentikan migrasi massal dengan keras kalau satu tenant gagal', function () {
    // PRD §5.4 poin 1: migrasi yang gagal di tenant ke-30 meninggalkan tenant
    // itu di skema lama. Yang dijaga di sini: kegagalannya tidak pernah
    // tertelan. `tenants:migrate` melempar, proses deploy berhenti dengan kode
    // keluar bukan nol, dan menjalankan ulang setelah perbaikan menuntaskannya.
    $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $dir = registerTemporaryTenantMigration(<<<'PHP'
        if (tenant('slug') === 'klinik-b' && ! file_exists(__DIR__.'/perbaikan')) {
            throw new RuntimeException('migrasi-gagal-di-klinik-b');
        }

        Schema::create('isolation_probe', function (Blueprint $table) {
            $table->id();
        });
PHP);

    try {
        expect(fn () => Artisan::call('tenants:migrate'))->toThrow(RuntimeException::class, 'migrasi-gagal-di-klinik-b');
        $b->run(fn () => expect(Schema::hasTable('isolation_probe'))->toBeFalse());

        File::put($dir.'/perbaikan', '');
        $this->artisan('tenants:migrate')->assertSuccessful();

        foreach (Tenant::all() as $tenant) {
            $tenant->run(fn () => expect(Schema::hasTable('isolation_probe'))->toBeTrue());
        }
    } finally {
        File::deleteDirectory($dir);
    }
});

// ─── 7. Koneksi pusat tidak bisa menjangkau data klinis ──────────────
//
//

it('tidak bisa menjangkau tabel klinis lewat koneksi pusat', function () {
    // Koneksi pusat login sebagai user MySQL yang hanya berhak atas database
    // pusat. Nama tabel berkualifikasi (`db_klinik.users`) — cara paling mudah
    // melewati isolasi di level aplikasi — ditolak MySQL. Kode pusat, termasuk
    // panel vendor, secara struktural tidak bisa membaca rekam medis (PRD §4.2).
    //
    // Regresi yang pernah terjadi: koneksi pusat login sebagai root, dan test
    // sebelumnya hanya memeriksa bahwa tabel `users` tidak ada DI database
    // pusat — lolos, sementara `SELECT * FROM db_klinik.users` berhasil.
    $a = $this->createTenant('klinik-a');
    $central = DB::connection(config('tenancy.database.central_connection'));

    expectDenied(fn () => $central->select("SELECT email FROM `{$a->db_name}`.users"));
    expectDenied(fn () => $central->select("SELECT * FROM `{$a->db_name}`.audit_logs"));
    expectDenied(fn () => $central->statement("CREATE DATABASE `{$a->db_name}_curian`"));
    expectDenied(fn () => $central->select('SELECT user FROM mysql.user'));

    $visible = array_map(fn ($row) => array_values((array) $row)[0], $central->select('SHOW DATABASES'));

    expect($visible)->not->toContain($a->db_name)
        ->and($central->getSchemaBuilder()->hasTable('users'))->toBeFalse();
});

it('tidak pernah membuka koneksi tenant dengan kredensial admin server', function () {
    // Template koneksi tenant adalah `tenancy_admin`. Tenant yang kehilangan
    // kredensialnya tidak boleh diam-diam berjalan sebagai admin.
    $a = $this->createTenant('klinik-a');

    $a->run(fn () => expect(DB::selectOne('SELECT CURRENT_USER() AS u')->u)
        ->not->toStartWith(config('database.connections.tenancy_admin.username').'@'));

    $a->forceFill(['db_username' => null, 'db_password' => null])->saveQuietly();

    expect(fn () => tenancy()->initialize($a->fresh()))->toThrow(LogicException::class, 'tidak punya user MySQL sendiri');
});

it('mengembalikan konteks ke pusat setelah selesai menjalankan kode tenant', function () {
    // Sumber bug paling halus di aplikasi multi-tenant: konteks yang tertinggal.
    // Kode berikutnya mengira sedang di database pusat, padahal masih di tenant.
    $tenant = $this->createTenant('klinik-a');

    $tenant->run(fn () => expect(tenancy()->initialized)->toBeTrue());

    expect(tenancy()->initialized)->toBeFalse()
        ->and(Tenant::count())->toBe(1);
});

// ─── 8. Celah yang pernah ditemukan ──────────────────────────────────
//
// Setiap test di bawah pernah gagal terhadap kode sungguhan sebelum celahnya
// ditutup. Jangan dihapus walaupun terlihat tumpang tindih dengan butir di atas.

it('mengunci login setelah percobaan gagal berulang tanpa mengunci klinik lain', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $this->createTenantUser($a, 'registrar', ['email' => 'rina@contoh.test', 'password' => PASSWORD_A]);
    $this->createTenantUser($b, 'registrar', ['email' => 'rina@contoh.test', 'password' => PASSWORD_B]);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $_) {
        $this->freshProcess();
        $this->post($this->tenantUrl($a, '/login'), ['email' => 'rina@contoh.test', 'password' => 'salah-tebak-1']);
    }

    $this->freshProcess();
    $locked = $this->post($this->tenantUrl($a, '/login'), ['email' => 'rina@contoh.test', 'password' => PASSWORD_A]);
    $locked->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest();

    // RateLimiter tidak melewati tag cache tenant; kunci throttle memuat id
    // tenant secara eksplisit. Tanpanya klinik B ikut terkunci.
    $this->freshProcess();
    $this->post($this->tenantUrl($b, '/login'), ['email' => 'rina@contoh.test', 'password' => PASSWORD_B])
        ->assertRedirect($this->tenantUrl($b, '/'))
        ->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

it('tidak menyajikan cache permission satu klinik untuk klinik lain', function () {
    // spatie/laravel-permission mengambil store cache lewat CacheManager::store(),
    // yang TIDAK melewati tag tenant. Kedua klinik menyemai role dengan urutan
    // sama, jadi `registrar` ber-id sama di keduanya — kalau cache bocor, klinik
    // B membaca peta permission milik A tanpa error apa pun.
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $registrarA = $this->createTenantUser($a, 'registrar');
    $registrarB = $this->createTenantUser($b, 'registrar');

    // Klinik A memutuskan petugas pendaftarannya boleh melihat laporan.
    $a->run(function () use ($registrarA) {
        Role::findByName('registrar')->givePermissionTo('report.view');
        expect(User::find($registrarA->id)->can('report.view'))->toBeTrue();
    });

    $b->run(fn () => expect(User::find($registrarB->id)->can('report.view'))->toBeFalse());
    $a->run(fn () => expect(User::find($registrarA->id)->can('report.view'))->toBeTrue());
    $b->run(fn () => expect(User::find($registrarB->id)->can('report.view'))->toBeFalse());
});

it('menyimpan role kustom hanya di klinik yang membuatnya', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $a->run(fn () => Role::create(['name' => 'bidan_koordinator']));

    $a->run(fn () => expect(Role::where('name', 'bidan_koordinator')->exists())->toBeTrue());
    $b->run(fn () => expect(Role::where('name', 'bidan_koordinator')->exists())->toBeFalse());
});

it('membuat tiga tenant berturut-turut yang semuanya berfungsi dan terisolasi', function () {
    $tenants = collect(['klinik-a', 'klinik-b', 'klinik-c'])
        ->map(fn (string $slug) => $this->createTenant($slug));

    foreach ($tenants as $tenant) {
        // Satu proses, tiga tenant: persis kondisi di mana singleton yang
        // terlanjur dibuat untuk tenant sebelumnya (broker password, cache
        // permission) menulis ke database yang salah.
        $tenant->run(function () use ($tenant) {
            expect(User::pluck('email')->all())->toBe(["admin@{$tenant->slug}.test"])
                ->and(DB::table('user_invitation_tokens')->pluck('email')->all())->toBe(["admin@{$tenant->slug}.test"])
                ->and(User::first()->hasRole('clinic_admin'))->toBeTrue();
        });

        $this->actingAs($this->adminOf($tenant))
            ->get($this->tenantUrl($tenant))
            ->assertOk();

        $this->freshProcess();
    }

    expect($tenants->pluck('db_username')->unique())->toHaveCount(3)
        ->and($tenants->pluck('db_name')->unique())->toHaveCount(3);
});

it('menyimpan jejak audit di database klinik masing-masing', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $this->actingAs($this->createTenantUser($a, 'registrar'))->get($this->tenantUrl($a, '/users'));

    expect(auditOf($a, AuditEvent::AccessDenied))->toHaveCount(1)
        ->and(auditOf($b, AuditEvent::AccessDenied))->toHaveCount(0)
        ->and(auditOf($b)->pluck('auditable_label')->implode(' '))->not->toContain('klinik-a');
});
