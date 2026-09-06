# Sprint 1 — Fondasi Multi-Tenant
## SIM Klinik SaaS · Fase F0

| | |
|---|---|
| **Durasi** | 2 minggu (10 hari kerja) |
| **Kapasitas asumsi** | 40 jam (~4 jam/hari, developer tunggal) |
| **Fase PRD** | F0 — Fondasi |
| **Stack** | Laravel 11 · Inertia · React 19 · Vite · MySQL 8 · Redis · `stancl/tenancy` |

---

## 1. Sprint Goal

> **Aku bisa membuat klinik baru dari satu perintah, dan dalam 3 menit klinik itu punya database sendiri, admin yang bisa login di subdomainnya, dan tidak ada satu pun cara untuk melihat data klinik lain.**

Satu kalimat, dan bisa didemokan. Kalau di akhir sprint kamu tidak bisa memperagakan kalimat itu di depan layar, sprint ini belum selesai — berapa pun task yang tercentang.

**Kenapa goal-nya ini dan bukan "selesaikan modul pendaftaran":** isolasi tenant adalah satu-satunya bagian sistem yang kalau salah, tidak bisa ditambal belakangan. Semua modul klinis dibangun di atasnya. Kesalahan di sini berarti membongkar ulang seluruh lapisan data setelah ada tenant nyata di dalamnya.

---

## 2. Yang TIDAK Masuk Sprint Ini

Ditulis eksplisit supaya tidak ada godaan menyerempet:

- Pendaftaran pasien, antrian, encounter — semua modul klinis (itu Sprint 2)
- Integrasi SATUSEHAT apa pun, termasuk OAuth2-nya
- Desain UI yang dipoles — cukup layout dan komponen dasar
- Billing dan langganan
- Halaman landing / marketing
- Deployment production (staging saja)

---

## 3. Definition of Done (berlaku untuk semua task)

Sebuah task selesai kalau:

1. Kode ter-commit dengan pesan yang menjelaskan *kenapa*, bukan cuma *apa*
2. Ada minimal satu test yang gagal kalau perilakunya rusak
3. Jalan di environment lokal dari `git clone` + satu perintah setup
4. Tidak menambah warning atau error baru di log
5. Kalau menyentuh data tenant: **ada test isolasi yang membuktikan tenant lain tidak terpengaruh**

Poin 5 tidak bisa ditawar. Itu inti sprint ini.

---

## 4. Backlog Sprint

| ID | Task | Est. | Prioritas |
|---|---|---|---|
| **S1-01** | Setup proyek & tooling | 4j | Must |
| **S1-02** | Central schema & model tenant | 4j | Must |
| **S1-03** | `stancl/tenancy` + resolusi subdomain | 5j | Must |
| **S1-04** | Provisioning tenant otomatis | 5j | Must |
| **S1-05** | Autentikasi tenant-scoped | 4j | Must |
| **S1-06** | RBAC (role & permission) | 4j | Must |
| **S1-07** | Audit trail | 5j | Must |
| **S1-08** | Queue tenant-aware + Horizon | 3j | Must |
| **S1-09** | Suite test isolasi tenant | 3j | Must |
| **S1-10** | Admin panel vendor (minimal) | 4j | Should |
| **S1-11** | Deploy ke VPS staging | 4j | Should |
| **S1-12** | Buffer & perbaikan | 3j | — |
| | **Total** | **48j** | |

**Catatan soal 48 vs 40 jam:** ini disengaja. S1-10, S1-11, dan S1-12 adalah bantalan. Kalau minggu pertama meleset, yang dikorbankan adalah admin panel (bisa pakai `artisan` dulu) dan deploy staging (bisa geser ke Sprint 2). Yang **tidak boleh** dikorbankan: S1-03, S1-04, S1-07, S1-09.

---

## 5. Rincian Task

### S1-01 — Setup Proyek & Tooling (4j)

**Kerjakan**
- Laravel 11 baru, PHP 8.3, MySQL 8, Redis
- Inertia + React 19 + Vite + Tailwind + shadcn/ui
- Pest untuk testing
- Docker Compose untuk dev (MySQL, Redis) — supaya bisa reset bersih
- `.env.example` lengkap, README dengan langkah setup
- Git repo + branch strategy sederhana (`main` + feature branch)

**Selesai kalau**
- `git clone` → `make setup` (atau satu skrip) → aplikasi jalan di `http://simklinik.test`
- `php artisan test` hijau
- Halaman React kosong ter-render lewat Inertia

**Jebakan**
Wildcard subdomain di lokal. Pakai `*.simklinik.test` lewat dnsmasq atau `.localhost` (Chrome dan Firefox meresolve `*.localhost` ke 127.0.0.1 secara otomatis). Selesaikan ini di hari pertama — kalau tidak, kamu tidak bisa menguji multi-tenant sama sekali.

---

### S1-02 — Central Schema & Model Tenant (4j)

**Kerjakan**

```
tenants
  id (uuid)  ·  slug (unique, = subdomain)  ·  name
  db_host  ·  db_port  ·  db_name  ·  db_user  ·  db_password (encrypted)
  status: provisioning | active | read_only | suspended
  plan  ·  created_at  ·  activated_at

domains
  id  ·  tenant_id  ·  domain (unique)

tenant_settings
  tenant_id  ·  key  ·  value        -- konfigurasi per klinik
```

**Selesai kalau**
- Migrasi central jalan
- Model `Tenant` dengan cast terenkripsi untuk `db_password`
- Factory + seeder untuk 3 tenant dummy

**Keputusan yang sudah diambil (jangan diperdebatkan lagi saat coding)**
`db_host` dan `db_port` disimpan sejak sekarang walaupun semuanya di satu server. Ini yang nanti memungkinkan pindah tenant ke DB server lain tanpa ubah kode.

---

### S1-03 — `stancl/tenancy` + Resolusi Subdomain (5j)

**Kerjakan**
- Install & konfigurasi `stancl/tenancy` dengan mode multi-database
- Middleware `InitializeTenancyByDomain` di route group tenant
- Pisahkan `routes/tenant.php` dan `routes/central.php` dengan tegas
- Subdomain tidak dikenal → halaman 404 yang ramah, bukan stack trace
- Tenant berstatus `suspended` → halaman penjelasan; status `read_only` → banner peringatan, tulis diblokir

**Selesai kalau**
- `klinik-a.simklinik.test` dan `klinik-b.simklinik.test` mengarah ke database berbeda
- Test: query model di konteks tenant A tidak mengembalikan baris milik tenant B
- Route central tidak bisa diakses dari subdomain tenant, dan sebaliknya

**Jebakan**
Cache, session, dan Redis juga harus di-scope per tenant. `stancl/tenancy` punya bootstrapper untuk ini — aktifkan sejak awal, jangan nanti. Session yang bocor antar tenant artinya user tenant A bisa terbawa sesi ke tenant B.

---

### S1-04 — Provisioning Tenant Otomatis (5j)

**Kerjakan**
- Command `artisan tenant:create {slug} {nama} {email-admin}`
- Alur di dalam job (bukan sinkron): buat database → buat user MySQL khusus → jalankan migrasi tenant → seed data awal (role, permission, poli default) → buat akun admin → kirim email undangan → set status `active`
- Rollback otomatis kalau salah satu langkah gagal (hapus database yang terlanjur dibuat, jangan tinggalkan tenant setengah jadi)
- Command `artisan tenant:delete {slug}` dengan konfirmasi ganda dan ekspor otomatis sebelum hapus

**Selesai kalau**
- Satu perintah → tenant siap login dalam < 3 menit
- Kegagalan di tengah tidak meninggalkan sampah
- Test: buat 3 tenant berturut-turut, semuanya berfungsi dan terisolasi

**Kenapa ini prioritas tinggi**
Ini yang membuat produk jadi SaaS, bukan sekadar aplikasi yang di-copy. Selama provisioning masih manual, tenant ke-10 sama capeknya dengan tenant ke-1.

---

### S1-05 — Autentikasi Tenant-Scoped (4j)

**Kerjakan**
- Laravel Breeze (stack Inertia + React) atau Fortify, dikonfigurasi untuk konteks tenant
- Tabel `users` ada di **database tenant**, bukan central
- Login hanya berlaku di subdomain tenant tempat user terdaftar
- Auto-logout setelah idle 15 menit (dapat dikonfigurasi per tenant) — FR-M21.6
- Reset password per tenant
- Rate limiting di endpoint login

**Selesai kalau**
- User tenant A tidak bisa login di subdomain tenant B, walaupun email dan password sama
- Test: sesi tenant A tidak valid di tenant B

**Ditunda ke sprint lain:** 2FA (FR-M21.5). Penting, tapi bukan blocker fondasi.

---

### S1-06 — RBAC (4j)

**Kerjakan**
- `spatie/laravel-permission`, tabelnya di database tenant
- Seed 8 role bawaan dari PRD: `clinic_admin`, `registrar`, `nurse`, `practitioner`, `pharmacist`, `lab_staff`, `cashier`, `medical_record_officer`
- Permission dengan pola `modul.aksi` (`patient.view`, `encounter.create`, `prescription.sign`)
- Middleware route + Policy skeleton
- Helper di React: `can('patient.view')` dari props Inertia, untuk menyembunyikan menu

**Selesai kalau**
- Role tersimpan per tenant (tenant A bisa punya role kustom yang tidak ada di tenant B)
- Akses tanpa permission → 403, dan menu-nya memang tidak muncul di UI

**Catatan**
Permission untuk modul yang belum dibangun tetap di-seed sekarang. Lebih murah menambahkannya di seeder awal daripada menyebar migrasi tambal-sulam nanti.

---

### S1-07 — Audit Trail (5j)

**Kerjakan**
- Trait `Auditable` yang mencatat create/update/delete lewat model observer
- **Pencatatan akses baca** untuk model yang ditandai sensitif (FR-M22.1) — siapa, kapan, record mana, IP
- Simpan nilai sebelum dan sesudah untuk perubahan
- Tabel audit append-only: cabut permission `DELETE` dan `UPDATE` pada tabel itu di level user MySQL aplikasi
- Blokir hard delete pada model klinis di level base model — `delete()` melempar exception, hanya `amend()` yang diizinkan

**Selesai kalau**
- Test: percobaan menghapus baris audit gagal di level database, bukan cuma di level aplikasi
- Test: `delete()` pada model klinis melempar exception
- Halaman sederhana untuk melihat log audit (belum perlu cantik)

**Kenapa 5 jam dan bukan 2**
Pencatatan akses baca itu yang bikin mahal. Kalau naif, setiap halaman daftar pasien menulis ratusan baris log. Perlu strategi: catat per akses *rekam medis pasien tertentu*, bukan per baris yang muncul di tabel daftar. Pikirkan ini dulu sebelum menulis kode.

---

### S1-08 — Queue Tenant-Aware + Horizon (3j)

**Kerjakan**
- Redis queue + Horizon, dengan otentikasi di halaman dashboard-nya
- Semua job membawa identitas tenant dan menginisialisasi tenancy saat dijalankan
- Base class `TenantAwareJob` supaya tidak ada yang lupa
- Job percobaan: kirim email undangan admin tenant

**Selesai kalau**
- **Test kritis:** dispatch job dari tenant A, jalankan worker, pastikan job menulis ke database tenant A — bukan ke database terakhir yang aktif di worker
- Horizon menampilkan job dengan tag tenant

**Ini risiko kritis di tabel risiko PRD.** Job yang berjalan di konteks tenant salah akan mengirim data pasien klinik A dengan kredensial SATUSEHAT klinik B. Buktikan sekarang, saat ongkosnya masih murah.

---

### S1-09 — Suite Test Isolasi Tenant (3j)

**Kerjakan** — satu file test yang isinya khusus membuktikan isolasi:

1. Model tenant A tidak terlihat dari konteks tenant B
2. Sesi/login tidak menyeberang antar tenant
3. Cache key tidak bertabrakan antar tenant
4. Job dieksekusi di konteks tenant yang benar
5. File upload tersimpan di direktori terpisah per tenant
6. Migrasi baru terpasang di semua tenant
7. Koneksi central tidak bisa dipakai untuk query tabel klinis

**Selesai kalau**
- Semua hijau, dan masuk ke CI sebagai gerbang wajib sebelum merge

**Perlakukan file ini sebagai aset paling berharga di repo.** Setiap kali kamu menemukan celah isolasi baru di masa depan, tambahkan test-nya di sini dulu, baru perbaiki.

---

### S1-10 — Admin Panel Vendor Minimal (4j) · *Should*

**Kerjakan**
- Halaman di domain central (`admin.simklinik.test`)
- Daftar tenant + status + tanggal dibuat
- Form buat tenant baru (memanggil job provisioning)
- Ubah status: aktifkan / read-only / suspend
- Login vendor terpisah dari login tenant

**Belum termasuk:** dashboard kesehatan tenant, metrik sinkronisasi, billing. Itu setelah ada data untuk ditampilkan.

**Boleh dipotong kalau waktu mepet** — `artisan tenant:create` sudah cukup untuk 3 klien pertama.

---

### S1-11 — Deploy ke VPS Staging (4j) · *Should*

**Kerjakan**
- Provisioning VPS: Nginx, PHP 8.3-FPM, MySQL 8, Redis, supervisor untuk queue worker
- **Wildcard DNS + sertifikat wildcard Let's Encrypt lewat DNS-01** (butuh DNS di penyedia yang punya API, mis. Cloudflare)
- Firewall: hanya 22, 80, 443. MySQL dan Redis bind ke `127.0.0.1`
- Skrip deploy sederhana (git pull → composer → build → migrate → restart)
- Buat 1 tenant demo di staging

**Selesai kalau**
- `demo.simklinik.id` bisa diakses dengan HTTPS valid
- Provisioning tenant jalan di server, bukan cuma di lokal

**Jebakan yang memakan waktu**
Let's Encrypt tidak bisa menerbitkan sertifikat wildcard lewat HTTP-01. Wajib DNS-01, artinya certbot butuh kredensial API DNS. Kalau DNS-mu di registrar tanpa API, pindahkan ke Cloudflare dulu. **Kerjakan bagian DNS ini di awal minggu kedua**, jangan di hari terakhir — propagasi dan trial-error bisa memakan setengah hari sendiri.

---

## 6. Jadwal Harian

### Minggu 1 — Fondasi Berdiri

| Hari | Fokus | Target akhir hari |
|---|---|---|
| **1** | S1-01 | Aplikasi jalan, wildcard subdomain lokal berfungsi |
| **2** | S1-02 + mulai S1-03 | Schema central jadi, `stancl/tenancy` terpasang |
| **3** | S1-03 | Dua subdomain mengarah ke dua database berbeda |
| **4** | S1-04 | `artisan tenant:create` menghasilkan tenant yang berfungsi |
| **5** | S1-04 + S1-05 | Admin tenant bisa login di subdomainnya |

**Checkpoint akhir Minggu 1 — jawab jujur:**
> Bisakah aku membuat tenant baru dengan satu perintah, lalu login sebagai adminnya?

Kalau **belum**, jangan lanjut ke S1-06. Selesaikan dulu di hari 6, dan potong S1-10 dari sprint. Menumpuk RBAC di atas fondasi yang belum kokoh cuma menambah utang.

### Minggu 2 — Pengamanan & Pembuktian

| Hari | Fokus | Target akhir hari |
|---|---|---|
| **6** | S1-06 | Role & permission jalan, menu menyesuaikan role |
| **7** | S1-07 | Audit trail mencatat tulis dan baca; hard delete diblokir |
| **8** | S1-08 + S1-09 | Job tenant-aware terbukti; suite isolasi hijau |
| **9** | S1-10 + mulai S1-11 (DNS dulu) | Admin panel jalan; DNS & sertifikat beres |
| **10** | S1-11 + S1-12 | Staging hidup; demo; retro |

---

## 7. Skrip Demo Akhir Sprint

Peragakan berurutan. Kalau semua lolos, sprint berhasil:

1. `artisan tenant:create klinik-melati "Klinik Melati" admin@melati.id` → tunggu, tenant aktif
2. Buka `klinik-melati.simklinik.id`, login sebagai admin
3. Buat user baru dengan role `registrar`
4. Logout, login sebagai registrar → menu administratif tidak muncul, akses langsung ke URL-nya kena 403
5. Buat tenant kedua `klinik-anggrek`
6. Coba login pakai kredensial admin Melati di subdomain Anggrek → **ditolak**
7. Tunjukkan halaman audit log: siapa login, siapa membuat user, kapan
8. Coba hapus baris audit lewat tinker → **gagal di level database**
9. Jalankan `php artisan test` → semua hijau, termasuk suite isolasi
10. Buka Horizon, tunjukkan job dengan tag tenant

---

## 8. Risiko Sprint

| Risiko | Tanda awal | Rencana |
|---|---|---|
| Wildcard subdomain lokal memakan waktu | Hari 1 belum selesai | Pakai `.localhost` (auto-resolve di browser modern), jangan berkutat di dnsmasq |
| `stancl/tenancy` punya kejutan konfigurasi | Hari 3 dua tenant belum terpisah | Baca dokumentasi bootstrapper cache/session dengan teliti; ini bagian yang paling sering salah konfigurasi |
| Audit akses baca membengkak | Log ribuan baris untuk satu halaman daftar | Batasi ke akses rekam medis individual, bukan listing |
| DNS-01 wildcard cert tersendat | Hari 9 sertifikat belum terbit | Mulai bagian DNS di hari 9 pagi; kalau gagal, staging pakai satu subdomain dulu tanpa wildcard |
| Tergoda mulai modul pasien | Merasa fondasi "membosankan" | Lihat kembali bagian 2 dokumen ini |

---

## 9. Penyesuaian Kapasitas

Kalau 40 jam bukan angka yang realistis buatmu:

| Kapasitas | Penyesuaian |
|---|---|
| **~20 jam** (2j/hari) | Jadikan 3 minggu, atau potong S1-10 dan S1-11 seluruhnya. Jangan potong S1-07 atau S1-09. |
| **~40 jam** | Rencana ini apa adanya. |
| **~70 jam** (full-time) | Selesai di hari ke-7. Sisa 3 hari: mulai S2 (skema pasien & encounter) atau tambahkan 2FA dan dashboard kesehatan tenant. |

**Aturan pemotongan, terurut dari yang pertama dipotong:**
S1-10 → S1-11 → sebagian S1-06 (cukup role dasar) → *berhenti di sini*.

S1-03, S1-04, S1-07, S1-08, dan S1-09 tidak dipotong dalam kondisi apa pun. Kelimanya adalah alasan sprint ini ada.

---

## 10. Retro — Tiga Pertanyaan

Isi di hari ke-10, tulis jawabannya, simpan:

1. Estimasi mana yang paling meleset, dan kenapa? (Ini yang mengkalibrasi Sprint 2.)
2. Adakah momen aku hampir menulis kode yang membocorkan data lintas tenant? Sudahkah ada test-nya sekarang?
3. Apa satu hal yang akan menghambatku di Sprint 2 kalau tidak dibereskan sekarang?
