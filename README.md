# SIMKlinik

Sistem Informasi Manajemen Klinik berbasis SaaS multi-tenant, dengan Rekam Medis
Elektronik yang dirancang **compliance-first** terhadap Permenkes 24/2022 dan
interoperabel dengan SATUSEHAT (HL7 FHIR R4).

Dokumen acuan: [PRD](PRD-SIM-Klinik-SaaS.md) · [Sprint 1 — Fondasi](Sprint-1-Fondasi.md)

**Status:** Sprint 1 (Fase F0 — Fondasi). Selesai sampai **S1-03**.

| Task | | |
|---|---|---|
| S1-01 | Setup proyek & tooling | ✅ |
| S1-02 | Central schema & model tenant | ✅ |
| S1-03 | `stancl/tenancy` + resolusi subdomain | ✅ |
| S1-04 | Provisioning tenant otomatis | ⬜ berikutnya |
| S1-05 … S1-11 | | ⬜ |

---

## Stack

Laravel 12 (PHP 8.3+) · Inertia.js · React 19 + TypeScript · Vite · Tailwind CSS v4 ·
MySQL 8 · Redis · [`stancl/tenancy`](https://tenancyforlaravel.com) (database-per-tenant) · Pest

## Kebutuhan

- PHP 8.3+ dengan `pdo_mysql`
- Composer 2
- Node.js 20+
- Docker (untuk MySQL 8 & Redis)

## Setup

```bash
git clone <repo> simklinik && cd simklinik
make setup
```

`make setup` menyalin `.env`, menyalakan MySQL + Redis lewat Docker, memasang
dependensi, menjalankan migrasi pusat, lalu membuat tiga klinik contoh beserta
database masing-masing.

Menjalankan aplikasi:

```bash
make dev     # server Laravel :8000 + Vite + worker antrian
```

| Alamat | Isi |
|---|---|
| <http://simklinik.localhost:8000> | Domain pusat (panel vendor menyusul di S1-10) |
| <http://klinik-melati.simklinik.localhost:8000> | Klinik aktif |
| <http://klinik-anggrek.simklinik.localhost:8000> | Klinik hanya-baca (banner peringatan) |
| <http://klinik-kamboja.simklinik.localhost:8000> | Klinik ditangguhkan (halaman penjelasan) |

Akun contoh tiap klinik: `admin@<slug>.test` / `password`.

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
│                                   ScopeSessions               │
│                                   EnsureTenantIsUsable        │
└───────────────┬───────────────────────────┬──────────────────┘
                ▼                           ▼
    ┌───────────────────────┐   ┌───────────────────────────────┐
    │  simklinik_central    │   │  simklinik_klinik_melati      │
    │  tenants · domains    │   │  simklinik_klinik_anggrek     │
    │  tenant_settings      │   │  simklinik_klinik_kamboja     │
    │  (tanpa data klinis)  │   │  users · (data klinis, S2)    │
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
| Status `read_only`, bukan blokir total | FR-M23.4 — tunggakan tagihan tidak boleh menutup akses baca rekam medis |

## Perintah

| | |
|---|---|
| `make setup` | Pasang semuanya dari nol |
| `make up` / `make down` | Nyalakan / matikan MySQL + Redis |
| `make dev` | Server + Vite + worker antrian |
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
