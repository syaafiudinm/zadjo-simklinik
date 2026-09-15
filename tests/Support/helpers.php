<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Helper bersama suite Feature
|--------------------------------------------------------------------------
|
| Dimuat sekali dari tests/Pest.php. Fungsi global Pest tidak boleh
| didefinisikan di dua berkas test sekaligus, jadi apa pun yang dipakai lebih
| dari satu berkas tinggal di sini.
|
*/

use App\Models\Polyclinic;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const PASSWORD_A = 'RahasiaKlinikA1';

const PASSWORD_B = 'RahasiaKlinikB2';

function setPassword(Tenant $tenant, string $email, string $password): User
{
    return $tenant->run(function () use ($email, $password) {
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['password' => $password, 'activated_at' => now()])->save();

        return $user;
    });
}

function loginVia($test, Tenant $tenant, string $email, string $password): string
{
    $response = $test->post($test->tenantUrl($tenant, '/login'), compact('email', 'password'));

    // Redirect ke beranda saja TIDAK membuktikan login berhasil: login yang
    // gagal memanggil back(), dan tanpa referer itu juga jatuh ke beranda.
    $response->assertRedirect($test->tenantUrl($tenant, '/'))->assertSessionHasNoErrors();
    $test->assertAuthenticated();

    return $response->getCookie(config('session.cookie'), decrypt: false)->getValue();
}

function asBrowser($test, string $sessionCookie)
{
    $test->freshProcess();

    return $test->withUnencryptedCookie(config('session.cookie'), $sessionCookie);
}

function adminDb(): Illuminate\Database\Connection
{
    return DB::connection(config('tenancy.database.admin_connection'));
}

function databaseExists(string $name): bool
{
    return adminDb()->selectOne('SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])->n > 0;
}

function mysqlUserExists(?string $name): bool
{
    return $name !== null && adminDb()->selectOne('SELECT COUNT(*) AS n FROM mysql.user WHERE user = ?', [$name])->n > 0;
}

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

function expectDenied(callable $query): void
{
    try {
        $query();
    } catch (QueryException $e) {
        // 1142 "command denied" untuk tabel, 1044/1227 "Access denied" untuk
        // database dan hak server.
        expect($e->getMessage())->toMatch('/command denied|Access denied/');

        return;
    }

    test()->fail('Query seharusnya ditolak MySQL, tapi berhasil.');
}
