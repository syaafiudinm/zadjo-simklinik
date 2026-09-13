<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Exceptions\TenantContextMismatchException;
use App\Http\Middleware\HorizonBasicAuth;
use App\Jobs\ProvisionTenant;
use App\Jobs\SendPasswordResetLink;
use App\Jobs\SendUserInvitation;
use App\Models\AuditLog;
use App\Models\Polyclinic;
use App\Models\Tenant;
use App\Support\QueueTimeoutGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\Jobs\RecordCentralContext;
use Tests\Fixtures\Jobs\RenamePolyclinic;

/*
|--------------------------------------------------------------------------
| S1-08 — Antrian tenant-aware + Horizon
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    RenamePolyclinic::$ranIn = [];
    RecordCentralContext::$tenancyWasInitialized = null;
    $this->queueName = 'test-'.Str::lower(Str::random(10));
});

afterEach(function () {
    DB::connection(config('tenancy.database.central_connection'))
        ->table('failed_jobs')->where('queue', $this->queueName)->delete();
});

function runWorker($test): void
{
    $test->artisan('queue:work', [
        'connection' => 'redis',
        '--queue' => $test->queueName,
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--tries' => 1,
    ])->assertSuccessful();
}

function failedJobs(string $queue)
{
    return DB::connection(config('tenancy.database.central_connection'))
        ->table('failed_jobs')->where('queue', $queue)->get();
}

function umumOf(Tenant $tenant): Polyclinic
{
    return $tenant->run(fn () => Polyclinic::where('code', 'UMUM')->firstOrFail());
}

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

it('menolak men-dispatch job tenant dari konteks pusat', function () {
    $tenant = $this->createTenant('klinik-a');
    $umum = umumOf($tenant);

    expect(fn () => RenamePolyclinic::dispatch($umum, 'X'))
        ->toThrow(RuntimeException::class, 'harus di-dispatch dari dalam konteks tenant');

    $tenant->run(fn () => expect(Polyclinic::where('code', 'UMUM')->value('name'))->toBe('Poli Umum'));
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

it('memberi tag tenant pada setiap job yang di-dispatch dari dalam klinik', function () {
    $tenant = $this->createTenant('klinik-melati');
    $umum = umumOf($tenant);
    $queue = $this->queueName;

    $tenant->run(function () use ($umum, $queue) {
        RenamePolyclinic::dispatch($umum, 'X')->onConnection('redis')->onQueue($queue);
    });
    RecordCentralContext::dispatch()->onConnection('redis')->onQueue($queue);

    $payloads = collect(Redis::connection(config('queue.connections.redis.connection'))->lrange("queues:{$queue}", 0, -1))
        ->map(fn (string $raw) => json_decode($raw, true));

    $tenantJob = $payloads->firstWhere('displayName', RenamePolyclinic::class);
    $centralJob = $payloads->firstWhere('displayName', RecordCentralContext::class);

    expect($tenantJob['tags'])->toContain('tenant:klinik-melati')
        ->and($tenantJob['tenant_id'])->toBe($tenant->id)
        ->and(collect($centralJob['tags'] ?? [])->filter(fn ($tag) => str_starts_with($tag, 'tenant:')))->toBeEmpty()
        ->and($centralJob)->not->toHaveKey('tenant_id');
});

it('tidak menyimpan token undangan maupun reset password di payload antrian', function () {
    // Horizon menampilkan payload job. Token di payload berarti operator vendor
    // bisa mengambil alih akun klinik.
    Queue::fake();
    $tenant = $this->createTenant('klinik-melati');
    $admin = $this->adminOf($tenant);

    $this->post($this->tenantUrl($tenant, '/forgot-password'), ['email' => 'admin@klinik-melati.test']);
    $this->freshProcess();
    $this->post($this->tenantUrl($tenant, '/forgot-password'), ['email' => 'tidak.terdaftar@luar.test']);

    // Email terdaftar maupun tidak: perlakuan identik.
    Queue::assertPushed(SendPasswordResetLink::class, 2);

    $payloads = [
        ...Queue::pushed(SendPasswordResetLink::class)->map(fn ($job) => $tenant->run(fn () => serialize($job)))->all(),
        $tenant->run(fn () => serialize(new SendUserInvitation($admin))),
    ];

    foreach ($payloads as $payload) {
        // Token broker Laravel: 64 karakter heksadesimal.
        expect($payload)->not->toMatch('/[a-f0-9]{64}/');
    }
});

it('memasang retry_after bawaan lebih besar dari timeout job dan supervisor terlama', function () {
    // Nilai BAWAAN di config/queue.php, dibaca tanpa variabel lingkungan —
    // `.env` pengembang yang kebetulan benar tidak boleh menutupi bawaan yang salah.
    $key = 'REDIS_QUEUE_RETRY_AFTER';
    $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);

    try {
        $defaults = require config_path('queue.php');
    } finally {
        if ($saved[0] !== false) {
            putenv("{$key}={$saved[0]}");
        }
        if ($saved[1] !== null) {
            $_ENV[$key] = $saved[1];
        }
        if ($saved[2] !== null) {
            $_SERVER[$key] = $saved[2];
        }
    }

    expect(QueueTimeoutGuard::longestTimeout())->toBeLessThan($defaults['connections']['redis']['retry_after']);
});

it('menolak menjalankan worker kalau retry_after terkonfigurasi terlalu kecil', function () {
    config(['queue.connections.redis.retry_after' => 90]);

    expect(fn () => event(new CommandStarting('horizon', new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'REDIS_QUEUE_RETRY_AFTER');

    // Perintah lain tidak terpengaruh.
    event(new CommandStarting('tenant:create', new ArrayInput([]), new NullOutput));

    config(['queue.connections.redis.retry_after' => 240]);
    event(new CommandStarting('horizon', new ArrayInput([]), new NullOutput));
});

it('menjalankan provisioning di antrian terpisah dari pekerjaan klinik', function () {
    expect((new ProvisionTenant('x'))->queue)->toBe('provisioning')
        ->and(collect(config('horizon.defaults'))->pluck('queue')->flatten()->all())
        ->toContain('provisioning', 'default');
});

// ─── Dashboard Horizon ───────────────────────────────────────────────────

function horizonUrl(): string
{
    return 'http://'.config('horizon.domain').'/'.config('horizon.path');
}

it('menutup dashboard Horizon tanpa kredensial yang benar', function () {
    $this->get(horizonUrl())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    $this->withBasicAuth('operator', 'tebakan')->get(horizonUrl())->assertUnauthorized();

    $this->withBasicAuth('operator', 'rahasia-test')->get(horizonUrl())->assertOk();
});

it('menutup dashboard Horizon sepenuhnya kalau kredensial belum dikonfigurasi, juga di lokal', function () {
    // Bawaan Horizon membuka dashboard tanpa syarat di lingkungan `local`.
    app()->detectEnvironment(fn () => 'local');
    config(['horizon.basic_auth.password' => null]);

    $this->withBasicAuth('operator', '')->get(horizonUrl())->assertForbidden();
});

it('menolak lewat gate Horizon kalau middleware basic auth tidak berjalan', function () {
    // Lapis cadangan: kalau HorizonBasicAuth tercabut dari config/horizon.php,
    // gate tetap menolak, termasuk di lingkungan `local`.
    app()->detectEnvironment(fn () => 'local');

    $bare = Request::create(horizonUrl());
    $passed = Request::create(horizonUrl());
    $passed->attributes->set(HorizonBasicAuth::PASSED, true);

    expect(Horizon::check($bare))->toBeFalse()
        ->and(Horizon::check($passed))->toBeTrue();
});

it('tidak melayani Horizon dari subdomain klinik', function () {
    $tenant = $this->createTenant('klinik-melati');

    $this->withBasicAuth('operator', 'rahasia-test')
        ->get($this->tenantUrl($tenant, '/'.config('horizon.path')))
        ->assertNotFound();
});
