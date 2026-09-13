# SIMKlinik

Sistem Informasi Manajemen Klinik berbasis SaaS multi-tenant, dengan Rekam Medis
Elektronik yang dirancang **compliance-first** terhadap Permenkes 24/2022 dan
interoperabel dengan SATUSEHAT (HL7 FHIR R4).

Dokumen acuan: [Arsitektur](docs/ARCHITECTURE.md) · [PRD](PRD-SIM-Klinik-SaaS.md) · [Sprint 1 — Fondasi](Sprint-1-Fondasi.md)

**Status:** Sprint 1 (Fase F0 — Fondasi). Selesai sampai **S1-08**.

| Task | | |
|---|---|---|
| S1-01 | Setup proyek & tooling | ✅ |
| S1-02 | Central schema & model tenant | ✅ |
| S1-03 | `stancl/tenancy` + resolusi subdomain | ✅ |
| S1-04 | Provisioning tenant otomatis | ✅ |
| S1-05 | Autentikasi tenant-scoped | ✅ |
| S1-06 | RBAC (role & permission) | ✅ |
| S1-07 | Audit trail | ✅ |
| S1-08 | Antrian tenant-aware + Horizon | ✅ |
| S1-09 | Suite test isolasi tenant | ⬜ berikutnya |
| S1-10 … S1-11 | | ⬜ |

---

## Stack

Laravel 12 (PHP 8.3+) · Inertia.js · React 19 + TypeScript · Vite · Tailwind CSS v4 ·
MySQL 8 · Redis · [`stancl/tenancy`](https://tenancyforlaravel.com) (database-per-tenant) ·
[`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission) · Laravel Horizon · Pest

## Kebutuhan

- PHP 8.3+ dengan `pdo_mysql`
- Composer 2
- Node.js 20+
- Docker (untuk MySQL 8, Redis, dan Mailpit)

## Setup

```bash
git clone <repo> simklinik && cd simklinik
make setup
```

`make setup` menyalin `.env`, menyalakan MySQL, Redis, dan Mailpit lewat Docker,
memasang dependensi, menjalankan migrasi pusat, lalu mem-provision tiga klinik
contoh lewat jalur yang sama dengan `tenant:create`.

Menjalankan aplikasi:

```bash
make dev     # server Laravel :8000 + Vite + Horizon (worker antrian)
```

| Alamat | Isi |
|---|---|
| <http://simklinik.localhost:8000> | Domain pusat (panel vendor menyusul di S1-10) |
| <http://klinik-melati.simklinik.localhost:8000> | Klinik aktif |
| <http://klinik-anggrek.simklinik.localhost:8000> | Klinik hanya-baca (banner peringatan) |
| <http://klinik-kamboja.simklinik.localhost:8000> | Klinik ditangguhkan (halaman penjelasan) |
| <http://localhost:8025> | Mailpit — email undangan & reset password |
| <http://admin.simklinik.localhost:8000/horizon> | Horizon — antrian (basic auth, lihat `HORIZON_BASIC_AUTH_*` di `.env`) |

Akun contoh tiap klinik (hanya lingkungan lokal):

| Email | Password | Peran |
|---|---|---|
| `admin@<slug>.test` | `password` | Admin Klinik |
| `registrar@<slug>.test` | `password` | Petugas Pendaftaran |

## Mengelola klinik

```bash
php artisan tenant:create klinik-melati "Klinik Melati" admin@melati.id
php artisan tenant:delete klinik-melati
```

`tenant:create` mendaftarkan klinik, lalu worker di antrian `provisioning` membuat database dan
**user MySQL khusus** dengan grant hanya ke database itu, menjalankan migrasi,
menyemai 8 role bawaan + 92 permission + poli default, membuat admin, dan
mengirim undangan. Admin menetapkan passwordnya sendiri lewat tautan — vendor
tidak pernah tahu password admin klinik. Butuh worker berjalan (`make dev`);
pakai `--sync` untuk menjalankannya tanpa worker.

Kalau satu langkah gagal, semua yang sudah dibuat dibongkar dan barisnya
dihapus. Database dengan nama sama yang **sudah ada sebelumnya** tidak pernah
disentuh rollback.

`tenant:delete` meminta konfirmasi dua kali (termasuk mengetik ulang slug) dan
selalu mengekspor seluruh isi database ke
`storage/app/private/tenant-exports/*.zip` sebelum menghapus. Tidak ada flag
untuk melewati ekspor. **Arsip itu berisi data kesehatan dan belum terenkripsi**
— izinnya 0600, jangan dipindahkan keluar server tanpa enkripsi.

> **Kenapa `.localhost` dan bukan `.test`:** Chrome, Safari, dan Firefox
> meresolve `*.localhost` ke 127.0.0.1 secara otomatis. Tidak perlu dnsmasq
> maupun menyunting `/etc/hosts`, dan tidak ada langkah setup yang berbeda antar
> mesin developer. Domainnya dibaca dari `CENTRAL_DOMAIN` di `.env`, jadi
> staging dan produksi tinggal mengganti satu baris.

## Test

```bash
make test
```

Suite memakai **MySQL sungguhan**, bukan SQLite in-memory: yang dibuktikan
adalah dua tenant benar-benar berada di dua database terpisah, dan SQLite akan
membuktikan hal itu di driver yang tidak pernah dipakai produksi.

Cache dan sesi di suite juga memakai Redis sungguhan (database 15). Driver
`array` membuat test isolasi cache dan sesi hijau karena alasan yang salah.

Berkas terpenting di repo ini adalah
[`tests/Feature/TenantIsolationTest.php`](tests/Feature/TenantIsolationTest.php).
Setiap kali ditemukan celah isolasi baru, **tambahkan test-nya di sana dulu,
baru perbaiki kodenya.**

## Arsitektur

```
┌──────────────────────────────────────────────────────────────┐
│  simklinik.localhost              klinik-x.simklinik.localhost│
│  routes/central.php               routes/tenant.php           │
│  PreventAccessFromTenantDomains   InitializeTenancyBySubdomain│
│                                   ScopeSessionToTenant        │
│                                   EnsureTenantIsUsable        │
│                                   auth · EnforceIdleTimeout   │
└───────────────┬───────────────────────────┬──────────────────┘
                ▼                           ▼
    ┌───────────────────────┐   ┌───────────────────────────────┐
    │  simklinik_central    │   │  simklinik_klinik_melati      │
    │  tenants · domains    │   │  simklinik_klinik_anggrek     │
    │  tenant_settings      │   │  simklinik_klinik_kamboja     │
    │  (tanpa data klinis)  │   │  users · roles · permissions  │
    │                       │   │  polyclinics · (klinis, S2)   │
    └───────────────────────┘   └───────────────────────────────┘
```

Rute pusat dan rute tenant dipisahkan pada **pencocokan rute**, bukan sekadar di
middleware: grup tenant terikat pola domain `{tenant}.<CENTRAL_DOMAIN>`,
sehingga sebuah path yang lupa dipasangi middleware tetap tidak bisa terjawab di
sisi yang salah.

### Keputusan yang sudah diambil

| Keputusan | Alasan |
|---|---|
| Database-per-tenant | Isolasi mudah dibuktikan saat audit Dinkes; ekspor data per klinik jadi sederhana; blast radius kecil |
| `db_host` & `db_port` disimpan sejak awal | Memindahkan tenant besar ke server DB terpisah nanti cukup UPDATE satu baris, tanpa ubah kode (PRD §5.4) |
| Nama database dari slug (`simklinik_klinik_melati`) | Terbaca manusia saat menelusuri `SHOW DATABASES` atau daftar backup |
| Kunci primer UUID v7 | Berurutan secara leksikografis, sehingga insert selalu di ujung indeks InnoDB |
| `db_password` dengan encrypted cast | Dump database saja tidak cukup untuk membuka kredensial tenant |
| Status `read_only`, bukan blokir total | FR-M23.4 — tunggakan tagihan tidak boleh menutup akses baca rekam medis. Login tetap diizinkan di mode ini |
| User MySQL per tenant | Isolasi kedua di level database: user klinik A ditolak MySQL saat membaca database klinik B, apa pun yang dilakukan kode |
| `clinic_admin` tanpa akses rekam medis | Mengelola klinik ≠ berhak membaca diagnosis (minimisasi akses UU PDP). Pemilik yang berpraktik diberi dua peran |
| Anti-eskalasi peran | Pengguna hanya bisa memberikan peran yang kuasa administratifnya (`user`, `role`, `audit_log`, `clinic_setting`, `satusehat`) sudah ia miliki |
| Tanpa "ingat saya" | Komputer klinik dipakai bergantian; logout idle 15 menit bawaan, bisa diatur per klinik (5–120) |

### Jebakan multi-tenant yang sudah ditambal

Setiap butir di bawah punya test yang terbukti gagal kalau penambalnya dibuang.

- **Cache yang tidak ter-tag tenant.** `CacheTenancyBootstrapper` hanya men-tag
  panggilan `cache()->get()`. Apa pun yang memanggil `store()`/`driver()`
  langsung — `RateLimiter` dan cache permission spatie — berbagi kunci antar
  klinik. Kunci throttle login dan kunci cache permission kini memuat id tenant.
- **Sesi klinik lain yang ditolak tapi tetap dibaca.** `ScopeSessions` bawaan
  paket menjawab 403 sambil membiarkan sesi utuh, dan halaman 403 me-resolve
  user dari sesi itu — identitas user ber-id sama di klinik lain bocor lewat
  props. Diganti `ScopeSessionToTenant` yang membakar sesi asing.
- **Singleton yang dibuat untuk tenant sebelumnya.** Broker password dan cache
  permission di-reset setiap pindah konteks; guard auth di-reset saat kembali ke
  pusat. Relevan untuk worker antrian dan perintah yang menyentuh banyak tenant.
- **Urutan middleware.** `auth` ada di daftar prioritas Laravel, gerbang status
  tenant tidak — sehingga klinik ditangguhkan mengarahkan tamu ke login. Urutan
  kini ditetapkan eksplisit di `TenancyServiceProvider`.

- **Hak DDL user runtime.** MySQL tidak bisa mencabut hak per tabel yang
  diberikan per database, jadi user MySQL tenant kini hanya memegang hak DML
  per tabel, dan migrasi berjalan lewat koneksi admin `tenant_migrator`. Hak
  disinkronkan otomatis setiap `tenants:migrate`; tenant lama ikut dirapikan
  saat migrasi berikutnya.
- **`dispatch()` yang lolos dari konteks tenant.** `$tenant->run(fn () => Job::dispatch())`
  mengembalikan `PendingDispatch` yang baru di-dispatch setelah `run()` selesai —
  tanpa konteks tenant. Pakai closure berblok. `TenantAwareJob` menolak
  dispatch dari konteks pusat, jadi kesalahan ini gagal keras.
- **`retry_after` lebih kecil dari timeout job.** Redis menyerahkan job yang
  masih berjalan ke worker kedua. Worker kini menolak start kalau invarian ini
  dilanggar.

## Menambah permission

Semua permission dan peran bawaan hidup di
[`app/Support/Rbac/PermissionCatalog.php`](app/Support/Rbac/PermissionCatalog.php).
Setelah mengubahnya, sinkronkan ke semua klinik — peran kustom tidak disentuh:

```bash
php artisan tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force
```

## Audit trail

Setiap perubahan data (`Auditable`), login/logout/gagal masuk, pemberian peran,
akses ditolak, dan sesi asing yang ditolak tercatat di tabel `audit_logs`
milik database klinik, dan terlihat di menu **Log Audit** (`audit_log.view`).

- **Append-only di level MySQL.** User runtime hanya memegang `SELECT, INSERT`
  atas `audit_logs`. `DB::table('audit_logs')->delete()` ditolak database —
  termasuk lewat tinker.
- **Akses baca rekam medis** dicatat per rekam medis yang dibuka, dengan
  middleware `audit.access:<parameter>` di rute detail (bukan rute daftar).
  Akses berulang oleh orang yang sama dalam 60 detik dicatat sekali.
- **Data klinis tanpa hard delete.** Model klinis (Sprint 2) mewarisi
  `App\Models\ClinicalModel`: `delete()` dan hapus massal melempar exception,
  koreksi lewat `amend($perubahan, $alasan)`. Tabelnya **wajib** didaftarkan
  tanpa `DELETE` di `config/simklinik.php → database_grants`; test gagal kalau lupa.

## Antrian

Job yang bekerja atas data satu klinik **wajib** mewarisi
`App\Jobs\TenantAwareJob` dan mengimplementasikan `handleForTenant()`:

```php
class KirimEncounter extends TenantAwareJob
{
    public function __construct(public Encounter $encounter) {}

    public function handleForTenant(SatuSehatClient $client): void { /* … */ }
}
```

Tenant pemilik ditangkap otomatis saat dispatch; sebelum `handleForTenant()`
berjalan, konteks worker diverifikasi sama dengan pemiliknya. Setiap job dari
dalam klinik mendapat tag `tenant:<slug>` di Horizon. Jangan menaruh token atau
rahasia di properti job — payload terlihat di dashboard Horizon.

## Perintah

| | |
|---|---|
| `make setup` | Pasang semuanya dari nol |
| `make up` / `make down` | Nyalakan / matikan MySQL + Redis |
| `make dev` | Server + Vite + Horizon |
| `make test` | Seluruh suite, termasuk isolasi tenant |
| `make fresh` | Hapus semua tenant, migrasi & seed ulang |
| `make tenants` | Daftar tenant beserta alamatnya |
| `make lint` | Pint + `tsc --noEmit` |

Migrasi tenant hidup di `database/migrations/tenant/` dan dijalankan dengan
`php artisan tenants:migrate` — terpisah dari migrasi pusat di
`database/migrations/`.

> **Migrasi wajib backward-compatible.** Satu codebase melayani semua tenant.
> Kalau `tenants:migrate` gagal di tenant ke-30, tenant itu tertinggal di skema
> lama sementara kodenya sudah baru. Polanya selalu *expand → deploy → backfill
> → contract* (PRD §5.4).

## Lisensi

MIT.
