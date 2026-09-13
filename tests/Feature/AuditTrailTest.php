<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Events\TenantProvisioningStepCompleted;
use App\Exceptions\HardDeleteForbiddenException;
use App\Http\Middleware\EnsureTenantIsUsable;
use App\Http\Middleware\ScopeSessionToTenant;
use App\Models\AuditLog;
use App\Models\ClinicalModel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantDatabaseGrants;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Tests\Fixtures\ClinicalNote;

/*
|--------------------------------------------------------------------------
| S1-07 — Audit trail
|--------------------------------------------------------------------------
*/

/** @return \Illuminate\Support\Collection<int, AuditLog> */
function auditOf(Tenant $tenant, ?AuditEvent $event = null)
{
    return $tenant->run(fn () => AuditLog::query()
        ->when($event, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get());
}

function expectDenied(callable $query): void
{
    try {
        $query();
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('command denied');

        return;
    }

    test()->fail('Query seharusnya ditolak MySQL, tapi berhasil.');
}

// ─── Append-only di level database ──────────────────────────────────────

it('menolak hapus dan ubah baris audit di level database, bukan hanya aplikasi', function () {
    // Skrip demo sprint langkah 8. Query mentah lewat DB::table() — tanpa
    // model, tanpa event — tetap ditolak, karena user MySQL runtime memang
    // tidak memegang UPDATE/DELETE atas tabel ini.
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        expect(DB::table('audit_logs')->count())->toBeGreaterThan(0);

        expectDenied(fn () => DB::table('audit_logs')->delete());
        expectDenied(fn () => DB::table('audit_logs')->update(['event' => 'dipalsukan']));
        expectDenied(fn () => DB::statement('TRUNCATE TABLE audit_logs'));
        expectDenied(fn () => DB::statement('DROP TABLE audit_logs'));

        // Menulis dan membaca tetap bisa — log audit memang harus bertambah.
        app(AuditLogger::class)->record(AuditEvent::AccessDenied, context: ['uji' => true]);
        expect(DB::table('audit_logs')->where('event', 'access_denied')->exists())->toBeTrue();
    });
});

it('tidak memberi user runtime tenant hak DDL apa pun', function () {
    // Satu celah SQL injection tidak boleh berujung DROP TABLE atau ALTER.
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        expectDenied(fn () => DB::statement('DROP TABLE users'));
        expectDenied(fn () => DB::statement('ALTER TABLE users ADD COLUMN x INT'));
        expectDenied(fn () => DB::statement('CREATE TABLE curian (id INT)'));
    });
});

it('mencabut hak level database milik tenant lama saat migrasi berikutnya', function () {
    // Tenant yang di-provision sebelum S1-07 memegang `GRANT … ON db.*`,
    // termasuk DELETE dan DROP. Deploy berikutnya menjalankan
    // `tenants:migrate`, dan itu harus cukup untuk menutup celahnya.
    $tenant = $this->createTenant('klinik-lama');
    $central = DB::connection(config('tenancy.database.central_connection'));
    $central->statement("GRANT ALL PRIVILEGES ON `{$tenant->db_name}`.* TO `{$tenant->db_username}`@`%`");

    $tenant->run(fn () => DB::table('audit_logs')->where('id', 0)->delete());

    $this->artisan('tenants:migrate', ['--tenants' => [$tenant->id]])->assertSuccessful();

    $tenant->run(fn () => expectDenied(fn () => DB::table('audit_logs')->where('id', 0)->delete()));
});

it('tidak pernah memberi user runtime hak level database, bahkan sebelum migrasi', function () {
    // Sinkronisasi hak setelah migrasi mencabut grant level database. Tapi di
    // antara CREATE USER dan migrasi pun, user runtime tidak boleh memegangnya.
    $schemaGrants = null;

    Event::listen(TenantProvisioningStepCompleted::class, function (TenantProvisioningStepCompleted $event) use (&$schemaGrants) {
        if ($event->step === 'create_database') {
            $schemaGrants = DB::connection(config('tenancy.database.central_connection'))->selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ?',
                ["'{$event->tenant->db_username}'@'%'"]
            )->n;
        }
    });

    $this->createTenant('klinik-melati');

    expect($schemaGrants)->toBe(0);
});

it('menolak ubah dan hapus log audit lewat model', function () {
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        $log = AuditLog::first();

        expect(fn () => $log->update(['event' => AuditEvent::Login]))->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $log->delete())->toThrow(LogicException::class, 'append-only');
    });
});

// ─── Jejak perubahan data ────────────────────────────────────────────────

it('mencatat pembuatan pengguna oleh admin beserta pemberian perannya', function () {
    // Skrip demo sprint langkah 7: "siapa membuat user, kapan".
    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);

    $this->actingAs($admin)->post($this->tenantUrl($tenant, '/users'), [
        'name' => 'Rina', 'email' => 'rina@melati.test', 'role' => 'registrar',
        'password' => 'RinaMelati123', 'password_confirmation' => 'RinaMelati123',
    ])->assertRedirect();

    $created = auditOf($tenant, AuditEvent::Created)->firstWhere('auditable_label', 'Rina <rina@melati.test>');
    $assigned = auditOf($tenant, AuditEvent::RoleAssigned)->firstWhere('auditable_label', 'Rina <rina@melati.test>');

    expect($created)->not->toBeNull()
        ->and($created->actor_id)->toBe($admin->id)
        ->and($created->actor_label)->toBe("{$admin->name} <{$admin->email}>")
        ->and($created->channel)->toBe('http')
        ->and($created->ip_address)->toBe('127.0.0.1')
        ->and($created->new_values['email'])->toBe('rina@melati.test')
        // Password dicatat "ada", nilainya tidak pernah.
        ->and($created->new_values['password'])->toBe(AuditLogger::MASK)
        ->and(json_encode($created->new_values))->not->toContain('$2y$')
        ->and($created->new_values['activated_at'])->not->toBeNull()
        // Satu kejadian pembuatan, tanpa "mengubah" susulan untuk pengguna yang sama.
        ->and(auditOf($tenant, AuditEvent::Updated)->where('auditable_label', 'Rina <rina@melati.test>'))->toBeEmpty()
        ->and($assigned->new_values)->toBe(['roles' => ['registrar']])
        ->and($assigned->actor_id)->toBe($admin->id);
});

it('mencatat nilai sebelum dan sesudah perubahan, tanpa kolom yang tidak bermakna', function () {
    $tenant = $this->createTenant('klinik-melati');

    $tenant->run(function () {
        $user = User::where('email', 'admin@klinik-melati.test')->first();
        $user->update(['name' => 'Pak Hendra', 'last_login_at' => now()]);

        $log = AuditLog::where('event', AuditEvent::Updated)->latest('id')->first();

        expect($log->old_values)->toBe(['name' => 'Admin Klinik klinik-melati'])
            ->and($log->new_values)->toBe(['name' => 'Pak Hendra']);

        // Perubahan yang HANYA menyentuh kolom dikecualikan tidak menulis apa pun.
        $before = AuditLog::count();
        $user->update(['last_login_at' => now()->addMinute()]);
        expect(AuditLog::count())->toBe($before);
    });
});

it('mencatat pembuatan akun admin oleh sistem provisioning', function () {
    $tenant = $this->createTenant('klinik-melati');

    $created = auditOf($tenant, AuditEvent::Created)->firstWhere('auditable_label', 'Admin Klinik klinik-melati <admin@klinik-melati.test>');

    expect($created)->not->toBeNull()
        ->and($created->actor_id)->toBeNull()
        ->and($created->channel)->toBe('console')
        ->and($created->ip_address)->toBeNull();
});

// ─── Autentikasi & keamanan ──────────────────────────────────────────────

it('mencatat login, gagal login, dan logout', function () {
    $tenant = $this->createTenant('klinik-melati');
    $tenant->run(fn () => User::where('email', 'admin@klinik-melati.test')->first()->forceFill(['password' => 'RahasiaKlinik1'])->saveQuietly());

    $this->post($this->tenantUrl($tenant, '/login'), ['email' => 'penebak@luar.test', 'password' => 'coba-coba']);
    $this->freshProcess();
    $this->post($this->tenantUrl($tenant, '/login'), ['email' => 'admin@klinik-melati.test', 'password' => 'RahasiaKlinik1'])
        ->assertSessionHasNoErrors();
    $this->post($this->tenantUrl($tenant, '/logout'));

    $failed = auditOf($tenant, AuditEvent::LoginFailed)->last();
    $login = auditOf($tenant, AuditEvent::Login)->last();
    $logout = auditOf($tenant, AuditEvent::Logout)->last();

    expect($failed->context)->toBe(['email' => 'penebak@luar.test'])
        ->and(json_encode($failed))->not->toContain('coba-coba')
        ->and($failed->actor_id)->toBeNull()
        ->and($login->auditable_label)->toBe('Admin Klinik klinik-melati <admin@klinik-melati.test>')
        ->and($login->actor_id)->toBe($login->auditable_id === null ? null : (int) $login->auditable_id)
        ->and($logout->context)->toBe(['reason' => 'manual']);
});

it('mencatat percobaan membuka halaman tanpa hak oleh pengguna yang sudah login', function () {
    $tenant = $this->createTenant('klinik-melati');
    $registrar = $this->createTenantUser($tenant, 'registrar');

    $this->actingAs($registrar)->get($this->tenantUrl($tenant, '/users'))->assertForbidden();

    $denied = auditOf($tenant, AuditEvent::AccessDenied)->last();

    expect($denied->actor_id)->toBe($registrar->id)
        ->and($denied->context)->toMatchArray(['method' => 'GET', 'path' => 'users', 'route' => 'users.index']);
});

it('mencatat sesi klinik lain yang ditolak di klinik yang dituju', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');
    $a->run(fn () => User::first()->forceFill(['password' => 'RahasiaKlinik1'])->saveQuietly());

    $login = $this->post($this->tenantUrl($a, '/login'), ['email' => 'admin@klinik-a.test', 'password' => 'RahasiaKlinik1']);
    $cookie = $login->getCookie(config('session.cookie'), decrypt: false)->getValue();

    $this->freshProcess();
    $this->withUnencryptedCookie(config('session.cookie'), $cookie)->get($this->tenantUrl($b, '/'));

    $rejected = auditOf($b, AuditEvent::SessionRejected)->last();
    expect($rejected)->not->toBeNull()
        ->and($rejected->context['session_tenant'])->toBe($a->id)
        ->and(auditOf($a, AuditEvent::SessionRejected))->toBeEmpty();
});

it('mengikat baris audit ke request yang memicunya', function () {
    $tenant = $this->createTenant('klinik-melati');

    $response = $this->actingAs($this->adminOf($tenant))->post($this->tenantUrl($tenant, '/users'), [
        'name' => 'Rina', 'email' => 'rina@melati.test', 'role' => 'registrar',
        'password' => 'RinaMelati123', 'password_confirmation' => 'RinaMelati123',
    ]);

    $requestId = $response->headers->get('X-Request-Id');
    $rows = $tenant->run(fn () => AuditLog::where('request_id', $requestId)->pluck('event')->map->value->all());

    expect($requestId)->not->toBeNull()
        ->and($rows)->toContain('created')
        ->and($rows)->toContain('role_assigned');
});

it('menyimpan jejak audit di database klinik masing-masing', function () {
    $a = $this->createTenant('klinik-a');
    $b = $this->createTenant('klinik-b');

    $this->actingAs($this->createTenantUser($a, 'registrar'))->get($this->tenantUrl($a, '/users'));

    expect(auditOf($a, AuditEvent::AccessDenied))->toHaveCount(1)
        ->and(auditOf($b, AuditEvent::AccessDenied))->toHaveCount(0)
        ->and(auditOf($b)->pluck('auditable_label')->implode(' '))->not->toContain('klinik-a');
});

// ─── Data klinis: tanpa hard delete ──────────────────────────────────────

it('menolak hard delete data klinis di setiap jalan yang tersedia', function () {
    $tenant = $this->createTenant('klinik-melati');
    ClinicalNote::install($tenant);

    $tenant->run(function () {
        $note = ClinicalNote::create(['patient_name' => 'Budi', 'diagnosis' => 'J06.9']);

        expect(fn () => $note->delete())->toThrow(HardDeleteForbiddenException::class)
            ->and(fn () => ClinicalNote::destroy($note->id))->toThrow(HardDeleteForbiddenException::class)
            ->and(fn () => ClinicalNote::where('id', $note->id)->delete())->toThrow(HardDeleteForbiddenException::class)
            ->and(fn () => ClinicalNote::query()->truncate())->toThrow(HardDeleteForbiddenException::class);

        // Jalan terakhir: query mentah tanpa Eloquent. Ditolak MySQL.
        expectDenied(fn () => DB::table(ClinicalNote::TABLE)->where('id', $note->id)->delete());

        expect(ClinicalNote::whereKey($note->id)->exists())->toBeTrue();
    });
});

it('mewajibkan alasan untuk amandemen dan mencatatnya bersama nilai lama', function () {
    $tenant = $this->createTenant('klinik-melati');
    ClinicalNote::install($tenant);

    $tenant->run(function () {
        $note = ClinicalNote::create(['patient_name' => 'Budi', 'diagnosis' => 'J06.9']);

        expect(fn () => $note->amend(['diagnosis' => 'J02.9'], 'salah'))
            ->toThrow(InvalidArgumentException::class, 'minimal 10 karakter');
        expect($note->fresh()->diagnosis)->toBe('J06.9');

        $note->amend(['diagnosis' => 'J02.9'], 'Salah pilih kode ICD-10 saat input awal');

        $log = AuditLog::where('event', AuditEvent::Amended)->latest('id')->first();

        expect($log->reason)->toBe('Salah pilih kode ICD-10 saat input awal')
            ->and($log->old_values)->toBe(['diagnosis' => 'J06.9'])
            ->and($log->new_values)->toBe(['diagnosis' => 'J02.9'])
            ->and($log->auditable_label)->toBe('Catatan Budi');

        // Perubahan biasa tetap tercatat sebagai `updated`, tanpa alasan.
        $note->update(['patient_name' => 'Budi Santoso']);
        expect(AuditLog::latest('id')->first()->event)->toBe(AuditEvent::Updated);
    });
});

it('mewajibkan setiap tabel model klinis terdaftar tanpa hak DELETE', function () {
    // Penjaga untuk Sprint 2: model klinis baru yang lupa didaftarkan di
    // config/simklinik.php → database_grants akan diam-diam boleh DELETE lewat
    // query mentah. Test ini membuat lupa itu gagal di CI.
    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, ClinicalModel::class)
            && ! (new ReflectionClass($class))->isAbstract());

    foreach ($models as $class) {
        $table = (new $class)->getTable();

        expect(TenantDatabaseGrants::privilegesFor($table))
            ->not->toContain('DELETE', "Tabel klinis [{$table}] ({$class}) masih boleh DELETE. Daftarkan di simklinik.database_grants.tables.");
    }

    expect(true)->toBeTrue();
});

// ─── Akses baca rekam medis ──────────────────────────────────────────────

function registerNoteRoute(): void
{
    Route::domain('{tenant}.'.config('tenancy.primary_central_domain'))
        ->middleware(['web', InitializeTenancyBySubdomain::class, ScopeSessionToTenant::class, EnsureTenantIsUsable::class, 'auth', 'permission:patient.view', 'audit.access:note'])
        ->get('/fixture-notes/{note}', fn (ClinicalNote $note) => response($note->diagnosis));

    Route::domain('{tenant}.'.config('tenancy.primary_central_domain'))
        ->middleware(['web', InitializeTenancyBySubdomain::class, ScopeSessionToTenant::class, EnsureTenantIsUsable::class, 'auth'])
        ->get('/fixture-notes', fn () => response(ClinicalNote::pluck('patient_name')->implode(',')));

    // Penolakan DI DALAM controller (policy per objek, mis. "dokter hanya
    // pasien yang pernah ia layani"), setelah middleware audit berjalan.
    Route::domain('{tenant}.'.config('tenancy.primary_central_domain'))
        ->middleware(['web', InitializeTenancyBySubdomain::class, ScopeSessionToTenant::class, EnsureTenantIsUsable::class, 'auth', 'audit.access:note'])
        ->get('/fixture-notes/{note}/restricted', fn (ClinicalNote $note) => abort(403));
}

it('mencatat akses baca satu rekam medis, sekali per jendela waktu', function () {
    registerNoteRoute();
    $tenant = $this->createTenant('klinik-melati');
    ClinicalNote::install($tenant);
    $note = $tenant->run(fn () => ClinicalNote::create(['patient_name' => 'Budi', 'diagnosis' => 'J06.9']));
    $doctor = $this->createTenantUser($tenant, 'practitioner');
    $nurse = $this->createTenantUser($tenant, 'nurse');

    $this->actingAs($doctor);
    foreach (range(1, 3) as $_) {
        $this->get($this->tenantUrl($tenant, "/fixture-notes/{$note->id}"))->assertOk();
    }

    expect(auditOf($tenant, AuditEvent::Viewed))->toHaveCount(1);

    $viewed = auditOf($tenant, AuditEvent::Viewed)->first();
    expect($viewed->actor_id)->toBe($doctor->id)
        ->and($viewed->auditable_id)->toBe((string) $note->id)
        ->and($viewed->auditable_label)->toBe('Catatan Budi');

    // Orang lain membuka rekam medis yang sama: baris sendiri.
    $this->freshProcess();
    $this->actingAs($nurse)->get($this->tenantUrl($tenant, "/fixture-notes/{$note->id}"))->assertOk();
    expect(auditOf($tenant, AuditEvent::Viewed))->toHaveCount(2);

    // Setelah jendela lewat, akses berikutnya tercatat lagi.
    $this->travel(config('simklinik.audit.access_dedup_seconds') + 1)->seconds();
    $this->freshProcess();
    $this->actingAs($doctor)->get($this->tenantUrl($tenant, "/fixture-notes/{$note->id}"))->assertOk();
    expect(auditOf($tenant, AuditEvent::Viewed))->toHaveCount(3);
});

it('tidak mencatat baris daftar sebagai akses rekam medis, dan tidak mencatat akses yang ditolak', function () {
    registerNoteRoute();
    $tenant = $this->createTenant('klinik-melati');
    ClinicalNote::install($tenant);
    $note = $tenant->run(function () {
        ClinicalNote::create(['patient_name' => 'Siti', 'diagnosis' => 'I10']);

        return ClinicalNote::create(['patient_name' => 'Budi', 'diagnosis' => 'J06.9']);
    });

    $this->actingAs($this->createTenantUser($tenant, 'practitioner'))
        ->get($this->tenantUrl($tenant, '/fixture-notes'))->assertOk();

    // clinic_admin sengaja tanpa patient.view.
    $this->freshProcess();
    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant, "/fixture-notes/{$note->id}"))->assertForbidden();

    $this->freshProcess();
    $this->actingAs($this->createTenantUser($tenant, 'practitioner'))
        ->get($this->tenantUrl($tenant, "/fixture-notes/{$note->id}/restricted"))->assertForbidden();

    expect(auditOf($tenant, AuditEvent::Viewed))->toHaveCount(0)
        ->and(auditOf($tenant, AuditEvent::AccessDenied))->toHaveCount(2);
});

// ─── Halaman log audit ───────────────────────────────────────────────────

it('menampilkan log audit kepada admin klinik dan menyaring per kejadian', function () {
    $tenant = $this->createTenant('klinik-melati');
    $this->actingAs($this->createTenantUser($tenant, 'registrar'))->get($this->tenantUrl($tenant, '/users'));

    $this->freshProcess();
    $this->actingAs($this->adminOf($tenant))
        ->get($this->tenantUrl($tenant, '/audit-logs?event=access_denied'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('AuditLogs/Index')
            ->where('logs.total', 1)
            ->where('logs.data.0.eventLabel', 'Akses ditolak')
            ->where('logs.data.0.securitySignal', true));

    $this->get($this->tenantUrl($tenant, '/audit-logs'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('logs.data', fn ($rows) => collect($rows)->pluck('event')->contains('created')));
});

it('menolak halaman log audit bagi peran tanpa audit_log.view', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->actingAs($this->createTenantUser($tenant, 'nurse'))
        ->get($this->tenantUrl($tenant, '/audit-logs'))
        ->assertForbidden();
});
