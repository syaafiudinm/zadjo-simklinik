# Product Requirements Document (PRD)
## SIM Klinik SaaS — RME Terintegrasi SATUSEHAT

| | |
|---|---|
| **Nama Produk** | (TBD — placeholder: *SIMKlinik*) |
| **Versi Dokumen** | 1.0 |
| **Tanggal** | 6 September 2026 |
| **Penyusun** | Andi Syafiudin Musafir |
| **Status** | Draft untuk review |
| **Stack** | Laravel 11 (PHP 8.3) + Inertia.js + React 19 + Vite, MySQL 8, Redis, `stancl/tenancy` (database-per-tenant) |

---

## 1. Ringkasan Eksekutif

SIMKlinik adalah Sistem Informasi Manajemen Klinik berbasis SaaS multi-tenant untuk klinik pratama dan utama di Indonesia. Produk ini menggabungkan operasional klinik sehari-hari (pendaftaran, antrian, pelayanan, farmasi, kasir) dengan Rekam Medis Elektronik yang **compliant terhadap Permenkes No. 24 Tahun 2022** dan **terinteroperabilitas dengan SATUSEHAT Platform** melalui standar HL7 FHIR R4.

Diferensiasi utama: banyak SIM klinik di pasar Indonesia menjual "sudah bridging SATUSEHAT" sebagai fitur tempelan yang dikerjakan di akhir. Produk ini dirancang **compliance-first** — struktur data internalnya sejak awal memetakan ke resource FHIR, sehingga pengiriman data bukan proses transformasi yang rapuh melainkan konsekuensi alami dari pencatatan.

---

## 2. Masalah yang Diselesaikan

### 2.1 Konteks Regulasi

- Permenkes 24/2022 mewajibkan **seluruh fasyankes** — termasuk praktik mandiri — menyelenggarakan rekam medis secara elektronik. Batas migrasi 31 Desember 2023 sudah terlewat.
- UU No. 17 Tahun 2023 tentang Kesehatan mempertegas kewajiban interoperabilitas data melalui SATUSEHAT.
- Sanksi ketidakpatuhan bersifat administratif bertahap: teguran tertulis → denda → penghentian hak klaim BPJS Kesehatan → pencabutan izin operasional oleh Dinas Kesehatan.
- UU No. 27 Tahun 2022 (PDP) mengkategorikan data kesehatan sebagai **data pribadi bersifat spesifik**, dengan kewajiban tambahan soal dasar pemrosesan, keamanan, dan notifikasi kebocoran.

### 2.2 Pain Point Klinik Kecil–Menengah

| Pain Point | Dampak |
|---|---|
| SIMRS enterprise terlalu mahal & berat (lisensi puluhan–ratusan juta) | Klinik pratama tidak sanggup, akhirnya tetap manual |
| Solusi murah biasanya belum tersertifikasi / belum bridging SATUSEHAT | Klinik tetap non-compliant meski sudah bayar |
| Tidak ada tenaga IT internal | Butuh sistem yang zero-maintenance dari sisi klien |
| Double entry: catat di sistem klinik, lalu input ulang ke P-Care / SATUSEHAT | Beban kerja perawat & admin bertambah |
| Takut migrasi data & downtime | Resistensi adopsi |

### 2.3 Pain Point Vendor (kita sendiri)

Membangun satu-satu per klien tidak scalable. Setiap klien butuh mapping SATUSEHAT yang sama, terminologi yang sama, dan pemeliharaan yang sama. Model SaaS memungkinkan biaya compliance ditanggung sekali dan diamortisasi ke semua tenant.

---

## 3. Tujuan & Non-Tujuan

### 3.1 Tujuan Produk (Goals)

**G1 — Compliance.** Klinik yang memakai produk ini dapat dinyatakan memenuhi Permenkes 24/2022 tanpa pekerjaan tambahan.

**G2 — Interoperabilitas otomatis.** Setiap encounter yang diselesaikan terkirim ke SATUSEHAT tanpa aksi manual dari petugas.

**G3 — Zero double entry.** Data yang sudah diinput untuk operasional klinik tidak perlu diinput ulang untuk pelaporan.

**G4 — Time-to-value < 7 hari.** Dari kontrak sampai klinik bisa melayani pasien pertama di sistem.

**G5 — Skalabilitas SaaS.** Menambah tenant ke-50 tidak boleh butuh usaha manual yang berbeda dari tenant ke-5.

### 3.2 Non-Tujuan (Out of Scope v1)

- **Rawat inap penuh** (bed management, billing kamar, visite harian) — modul rawat inap SATUSEHAT punya 8 cluster resource dan kompleksitas jauh lebih tinggi; ditunda ke v2.
- **SIMRS rumah sakit tipe C ke atas** — segmen berbeda, butuh akreditasi KARS dan modul yang jauh lebih luas.
- **PACS / DICOM router** untuk radiologi — klinik pratama umumnya tidak punya modalitas imaging sendiri.
- **Telemedicine & aplikasi pasien** — v3.
- **Akuntansi penuh (jurnal, neraca, laba rugi)** — v1 hanya kasir & laporan pendapatan sederhana.
- **Modul use-case tematik lanjutan** (Registrasi Jantung, Kanker, Stroke, Uronefrologi) — relevan untuk RS rujukan, bukan klinik pratama.

---

## 4. Target Pengguna & Persona

### 4.1 Segmen Tenant

| Segmen | Karakteristik | Prioritas |
|---|---|---|
| Klinik Pratama (rawat jalan) | 1–5 dokter, 200–1500 kunjungan/bulan, kerja sama BPJS | **Primer** |
| Klinik Gigi | 1–3 dokter gigi, butuh odontogram | **Primer** |
| Praktik Dokter Mandiri | 1 dokter, 1 admin | Sekunder |
| Klinik Utama (rawat jalan spesialis) | 5–15 dokter, ada lab sederhana | Sekunder |
| Puskesmas | Punya sistem sendiri (SIMPUS), pengadaan via tender | Tidak dikejar v1 |

### 4.2 Persona Pengguna (dalam tenant)

| Persona | Peran Sistem | Kebutuhan Utama |
|---|---|---|
| **Bu Rina** — Admin Pendaftaran | `registrar` | Input cepat, cari pasien lama dalam < 5 detik, cetak nomor antrian |
| **dr. Aditya** — Dokter Umum | `practitioner` | Form pemeriksaan yang tidak memperlambat konsultasi (target < 90 detik/pasien), riwayat pasien satu layar |
| **Ns. Dewi** — Perawat | `nurse` | Input TTV & anamnesis awal, triase |
| **Apt. Budi** — Apoteker | `pharmacist` | Terima resep elektronik, cek stok, dispense, potong stok otomatis |
| **Pak Hendra** — Pemilik/Manajer | `clinic_admin` | Laporan pendapatan, kunjungan, status kepatuhan SATUSEHAT |
| **Kami** — Vendor | `super_admin` (tenant-level tidak akses data klinis) | Provisioning tenant, monitoring health, billing |

> **Catatan desain penting:** `super_admin` vendor **tidak boleh** punya akses baca ke data klinis tenant secara default. Akses hanya lewat mekanisme *break-glass* yang ter-log, dengan persetujuan tenant. Ini penting untuk posisi hukum kita di bawah UU PDP (kita adalah *Prosesor* Data Pribadi, klinik adalah *Pengendali*).

---

## 5. Arsitektur & Model Tenancy

### 5.1 Prinsip Arsitektur

1. **Database-per-tenant** menggunakan `stancl/tenancy`. Isolasi data antar fasyankes mudah dibuktikan saat audit Dinkes/KARS, dan memudahkan ekspor data kalau tenant berhenti berlangganan. Paket ini menangani resolusi tenant, switching koneksi, dan migrasi per tenant — jangan bangun sendiri.
2. **Central database** menyimpan: daftar tenant, domain, langganan, kredensial SATUSEHAT terenkripsi, dan data master global (KFA, ICD-10, LOINC, SNOMED subset).
3. **Master data global di-cache di central**, di-sync berkala dari API Kemenkes, dibaca read-only oleh semua tenant. Jangan duplikasi kamus ICD-10 ke 50 database.
4. **Queue-based SATUSEHAT sync.** Pengiriman FHIR tidak pernah blocking terhadap UI. Semua lewat job dengan retry & dead-letter.
5. **Append-only untuk data klinis.** Tidak ada hard delete. Koreksi dibuat sebagai versi baru dengan alasan amandemen.
6. **Satu codebase, satu deployment.** Inertia menghapus lapisan API antara backend dan frontend. Permukaan API terpisah (`routes/api.php`) hanya dibuat untuk kebutuhan nyata — mis. aplikasi pasien di v3 — bukan sebagai default.

### 5.2 Diagram Logis

```
┌──────────────────────────────────────────────────────────────┐
│   Browser / Tablet — React 19 (komponen .jsx)                 │
│   dirender lewat Inertia; props dikirim dari controller       │
└───────────────┬──────────────────────────────────────────────┘
                │  HTTPS · kunjungan Inertia (session cookie)
                ▼
┌──────────────────────────────────────────────────────────────┐
│   Laravel 11  ·  Nginx + PHP-FPM                              │
│   middleware: auth → InitializeTenancy → RBAC → audit         │
│   Controller → Inertia::render('Encounter/Show', [...])       │
└───────────────┬──────────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────┐
│                  CENTRAL DATABASE (MySQL 8)                   │
│  tenants · domains · subscriptions · invoices                 │
│  satusehat_credentials (encrypted) · master_kfa               │
│  master_icd10 · master_loinc · master_snomed · audit_global   │
└───────────────┬──────────────────────────────────────────────┘
                │  stancl/tenancy — switching koneksi per request
    ┌───────────┼───────────┬───────────────┐
    ▼           ▼           ▼               ▼
┌────────┐ ┌────────┐ ┌────────┐     ┌────────────┐
│tenant_1│ │tenant_2│ │tenant_3│ ... │ tenant_n   │
│(klinik)│ │(klinik)│ │(klinik)│     │            │
└────────┘ └────────┘ └────────┘     └────────────┘
   patients · encounters · observations · conditions
   prescriptions · dispenses · invoices · audit_logs
   satusehat_sync_jobs · satusehat_payload_archive
                │
                ▼
    ┌───────────────────────────┐
    │  SATUSEHAT Sync Worker     │
    │  Laravel Queue · Redis     │
    │  dipantau via Horizon      │
    └───────────┬───────────────┘
                ▼
    ┌───────────────────────────┐
    │  SATUSEHAT Platform (FHIR) │
    │  OAuth2 · FHIR R4 endpoint │
    └───────────────────────────┘
```

### 5.3 Keputusan Teknis Kunci

| Keputusan | Pilihan | Alasan |
|---|---|---|
| Backend | Laravel 11 (PHP 8.3) | Ekosistem lengkap untuk aplikasi CRUD berat: auth, queue, validasi, migrasi, RBAC sudah tersedia |
| Database | MySQL 8 | Dukungan luas di hosting Indonesia, `JSON` column untuk arsip payload FHIR |
| Isolasi data | Database-per-tenant via `stancl/tenancy` | Audit, portabilitas data, blast radius kecil — tanpa menulis pool manager sendiri |
| Frontend | Inertia.js + React 19 + Vite | React sungguhan tanpa lapisan API; validasi dan error handling gratis dari Laravel |
| Komponen UI | shadcn/ui + Tailwind | Komponen dimiliki sendiri (bukan dependensi), mudah dikustomisasi untuk form klinis padat |
| Antrian & worker | Laravel Queue + Redis, dipantau Horizon | UI bawaan untuk melihat job gagal — kritis saat debugging sinkronisasi SATUSEHAT |
| Penjadwalan | Laravel Scheduler | Sync master data KFA/LOINC, retry job tertunda, backup terjadwal |
| Otorisasi | Laravel Policy + Gate, dengan `spatie/laravel-permission` | Permission granular per modul; role kustom per tenant |
| HTTP client SATUSEHAT | Laravel HTTP Client dengan retry & timeout eksplisit | — |
| Penyimpanan payload FHIR | Arsip JSON per transaksi, retensi 90 hari | Debugging & bukti pengiriman saat sengketa |
| Enkripsi kredensial | Laravel encrypted casts, `APP_KEY` per environment | Client secret SATUSEHAT tiap tenant berbeda; jangan simpan plaintext |
| ID internal | UUID v7 disimpan sebagai `BINARY(16)` | Menghindari enumerasi, sortable (penting untuk indeks MySQL), hemat 20 byte vs `CHAR(36)` |
| Testing | Pest, dengan test isolasi tenant sebagai suite wajib | — |
| Deployment | Nginx + PHP-FPM di VPS, deploy via Git + `artisan` | Sederhana; tidak butuh orkestrasi container di fase awal |

### 5.4 Catatan Implementasi Tenancy

`stancl/tenancy` menangani bagian tersulitnya, tapi ada empat hal yang tetap jadi tanggung jawabmu.

**1. Migrasi harus backward-compatible.**
Satu codebase melayani semua tenant. Kalau `artisan tenants:migrate` gagal di tenant ke-30, tenant itu tertinggal di skema lama sementara kode sudah baru. Polanya selalu *expand → deploy → backfill → contract*: tambah kolom nullable, deploy kode yang menulis ke kolom lama dan baru, backfill, baru hapus kolom lama di migrasi terpisah. Lebih lambat, tapi ini satu-satunya cara aman.

**2. Master data jangan di-join lintas database.**
Secara teknis MySQL mengizinkan `JOIN central_db.master_icd10` selama central dan tenant di server yang sama. Jangan. Begitu dilakukan, tenant tidak bisa lagi dipindah ke server database terpisah. Cache master data di Redis atau memori aplikasi, gabungkan di layer aplikasi.

**3. Simpan `db_host` di registry tenant sejak hari pertama.**
Walaupun sekarang semua di satu VPS. Ini yang nanti memungkinkan memindahkan tenant besar ke server DB terpisah tanpa mengubah kode. Satu kolom yang menyelamatkanmu dari refactor besar dua tahun lagi.

**4. Job antrian wajib tenant-aware.**
Job yang di-dispatch dalam konteks tenant harus membawa identitas tenant dan menginisialisasi tenancy saat dijalankan worker. `stancl/tenancy` menyediakan mekanismenya, tapi ini sumber bug halus yang sering muncul: job sinkronisasi SATUSEHAT yang berjalan di konteks tenant yang salah akan mengirim data pasien klinik A dengan kredensial klinik B. **Jadikan ini kasus uji wajib**, bukan asumsi.

**5. Batas koneksi MySQL tetap perlu dihitung.**
Database-per-tenant berarti setiap request membuka koneksi ke database berbeda. Dengan PHP-FPM, jumlah koneksi kira-kira sebanyak worker FPM aktif. Setel `pm.max_children` dan `max_connections` MySQL secara sadar, dan jadikan jumlah koneksi aktif sebagai metrik monitoring utama — gejala pertama kehabisan koneksi adalah error acak di klinik yang sedang ramai, dan itu sulit didiagnosis tanpa grafik.

---

## 6. Pemetaan Modul SATUSEHAT → Modul Produk

Ini adalah bagian inti PRD. Setiap modul produk secara eksplisit dipetakan ke resource FHIR yang digunakan SATUSEHAT.

### 6.1 Ringkasan Pemetaan

| # | Modul Produk | Modul SATUSEHAT | Resource FHIR Utama | Fase |
|---|---|---|---|---|
| M0 | Onboarding Fasyankes | Registrasi & Onboarding | `Organization`, `Location`, `Practitioner` | 1 |
| M1 | Pendaftaran & Identitas Pasien | Onboarding | `Patient` (search by NIK) | 1 |
| M2 | Antrian | — (internal) | — | 1 |
| M3 | Kunjungan / Encounter | Rawat Jalan | `Encounter` | 1 |
| M4 | Anamnesis | Rawat Jalan | `Condition`, `AllergyIntolerance`, `FamilyMemberHistory`, `MedicationStatement` | 2 |
| M5 | Pemeriksaan Fisik & TTV | Rawat Jalan | `Observation` | 2 |
| M6 | Diagnosis | Rawat Jalan | `Condition` (ICD-10) | 2 |
| M7 | Tindakan Medis | Rawat Jalan | `Procedure` (ICD-9-CM) | 2 |
| M8 | Rencana & Kesimpulan Klinis | Rawat Jalan | `ClinicalImpression`, `CarePlan` | 3 |
| M9 | Farmasi & Resep | Pelayanan Kefarmasian | `MedicationRequest`, `Medication`, `MedicationDispense`, `QuestionnaireResponse` | 3 |
| M10 | Laboratorium | Rawat Jalan (penunjang) | `ServiceRequest`, `Specimen`, `Observation`, `DiagnosticReport` | 4 |
| M11 | Resume Medis | Rawat Jalan | `Composition` | 4 |
| M12 | Rujukan | Rawat Jalan | `ServiceRequest`, `Encounter` | 4 |
| M13 | Odontogram (Klinik Gigi) | Gigi | `Observation`, `Procedure` | 5 |
| M14 | Imunisasi | Imunisasi | `Immunization` | 5 |
| M15 | KIA (ANC/PNC) | Antenatal & Postnatal Care | `Observation`, `Condition`, `Procedure` | 5 |
| M16 | Skrining PTM | Skrining PTM | `Observation`, `QuestionnaireResponse` | 5 |
| M17 | MTBS | MTBS | `Observation`, `Condition`, `CarePlan` | 6 |
| M18 | TB | Tuberkulosis | `Observation`, `CarePlan`, `Immunization` | 6 |
| M19 | Billing & Kasir | — (internal) | opsional: `ChargeItem`, `Invoice`, `Claim` | 3 |
| M20 | BPJS P-Care | — (API terpisah, bukan SATUSEHAT) | — | 4 |
| M21 | Manajemen Pengguna & Hak Akses | — (mandat Permenkes) | — | 1 |
| M22 | Audit Trail & Log | — (mandat Permenkes) | — | 1 |
| M23 | Admin Panel Vendor (SaaS) | — | — | 1 |

---

## 7. Spesifikasi Modul

### M0 — Onboarding Fasyankes ke SATUSEHAT

**Deskripsi.** Sebelum tenant bisa mengirim data apa pun, struktur organisasinya harus terdaftar dan setiap entitas harus punya nomor IHS.

**Requirement Fungsional**

- FR-M0.1 — Tenant admin dapat memasukkan `client_id`, `client_secret`, dan `organization_id` SATUSEHAT milik kliniknya. Disimpan terenkripsi di central DB.
- FR-M0.2 — Sistem melakukan OAuth2 token exchange dan menyimpan access token dengan auto-refresh sebelum kedaluwarsa.
- FR-M0.3 — Sistem menampilkan wizard onboarding 4 langkah:
  1. Verifikasi kredensial & koneksi (ping endpoint)
  2. Registrasi `Location` (ruang periksa, ruang tindakan, apotek, lab) → simpan IHS ID
  3. Registrasi `Practitioner` (dokter, perawat, apoteker berdasarkan NIK) → simpan IHS ID
  4. Uji kirim payload dummy ke environment staging
- FR-M0.4 — Setiap `Practitioner` internal wajib punya nomor IHS sebelum bisa ditugaskan ke encounter. Sistem memblokir penugasan jika belum.
- FR-M0.5 — Toggle environment: `staging` / `production`. Default staging saat tenant baru dibuat.

**Acceptance Criteria**

- Klinik dengan 3 dokter dan 4 ruangan selesai onboarding dalam < 30 menit tanpa bantuan vendor.
- Jika kredensial salah, error message menjelaskan penyebab spesifik (bukan "terjadi kesalahan").
- Status onboarding terlihat sebagai checklist dengan indikator hijau/merah per item.

---

### M1 — Pendaftaran & Identitas Pasien

**Deskripsi.** Identitas pasien di SATUSEHAT berbasis NIK. Pasien yang sudah terdaftar di ekosistem punya nomor IHS yang harus dipakai konsisten lintas fasyankes.

**Requirement Fungsional**

- FR-M1.1 — Input NIK 16 digit dengan validasi checksum dan format (validasi kode wilayah, tanggal lahir tertanam, jenis kelamin dari digit tanggal).
- FR-M1.2 — Setelah NIK diinput, sistem otomatis melakukan pencarian `Patient` by NIK ke SATUSEHAT. Jika ditemukan, form terisi otomatis (nama, tanggal lahir, jenis kelamin, alamat) dan IHS ID pasien tersimpan.
- FR-M1.3 — Jika tidak ditemukan, petugas mengisi manual. Sistem menandai pasien sebagai `ihs_pending` dan mencoba ulang pencarian secara terjadwal.
- FR-M1.4 — Dukungan pasien tanpa NIK (bayi baru lahir, WNA, pasien tidak sadarkan diri) dengan identitas sementara dan penandaan wajib dilengkapi.
- FR-M1.5 — Nomor Rekam Medis internal di-generate otomatis dengan format yang dapat dikonfigurasi per tenant.
- FR-M1.6 — Deteksi duplikat: peringatan jika ada pasien dengan NIK sama, atau kombinasi nama + tanggal lahir + nama ibu yang mirip.
- FR-M1.7 — Fitur merge pasien duplikat, dengan audit trail dan tanpa kehilangan riwayat encounter.
- FR-M1.8 — Pencatatan persetujuan (consent) pasien atas pemrosesan data kesehatan, sesuai UU PDP. Consent tersimpan dengan timestamp, versi teks persetujuan, dan metode (tanda tangan digital / paraf di tablet).

**Acceptance Criteria**

- Pendaftaran pasien lama: dari input NIK sampai nomor antrian tercetak < 20 detik.
- Pendaftaran pasien baru dengan lookup SATUSEHAT sukses: < 60 detik.
- Kegagalan koneksi SATUSEHAT **tidak boleh** memblokir pendaftaran. Sistem harus tetap jalan offline-tolerant dan sinkron belakangan.

---

### M2 — Antrian

**Deskripsi.** Modul internal, tidak dikirim ke SATUSEHAT, tapi kritis untuk adopsi harian.

**Requirement Fungsional**

- FR-M2.1 — Nomor antrian otomatis per poli per hari, reset tiap hari.
- FR-M2.2 — Konfigurasi prefix per poli (A untuk umum, G untuk gigi, dst).
- FR-M2.3 — Tampilan display antrian untuk layar ruang tunggu (halaman terpisah, mode kiosk, auto-refresh).
- FR-M2.4 — Panggilan antrian dengan Text-to-Speech Bahasa Indonesia.
- FR-M2.5 — Status antrian: `menunggu` → `dipanggil` → `dilayani` → `selesai` / `batal` / `tidak hadir`.
- FR-M2.6 — Estimasi waktu tunggu berdasarkan rata-rata durasi layanan 7 hari terakhir.
- FR-M2.7 — Antrian dapat disusun ulang oleh admin untuk kasus prioritas (lansia, ibu hamil, disabilitas, gawat darurat).

**Acceptance Criteria**

- Display antrian tetap sinkron dengan lag < 3 detik.
- Klinik dapat menjalankan display di TV murah via browser tanpa aplikasi tambahan.

---

### M3 — Kunjungan / Encounter

**Deskripsi.** `Encounter` adalah tulang punggung. Di SATUSEHAT, satu rangkaian rawat jalan didefinisikan sebagai satu Encounter, mencakup kapan pertemuan mulai dan selesai, siapa nakes yang melayani, dan siapa subjek pelayanannya.

**Requirement Fungsional**

- FR-M3.1 — Encounter dibuat saat pasien dipanggil ke ruang periksa, bukan saat pendaftaran.
- FR-M3.2 — Elemen wajib saat pembukaan encounter dikirim lebih dulu; elemen mandatoris lainnya dikirim saat encounter ditutup.
- FR-M3.3 — Status encounter mengikuti siklus FHIR: `arrived` → `in-progress` → `finished`, dengan `cancelled` untuk pembatalan.
- FR-M3.4 — Kelas encounter: `AMB` (rawat jalan) untuk v1, `EMER` (gawat darurat) untuk klinik dengan layanan UGD.
- FR-M3.5 — Encounter wajib punya: pasien (dengan IHS ID), practitioner (dengan IHS ID), lokasi (dengan IHS ID), periode waktu.
- FR-M3.6 — Diagnosis pada encounter dikirim dengan peran diagnosis (mis. Admission Diagnosis) dan `rank` untuk mengurutkan diagnosis utama vs sekunder.
- FR-M3.7 — Encounter yang belum ditutup dalam 24 jam memicu notifikasi ke admin klinik.
- FR-M3.8 — Encounter yang sudah `finished` dan terkirim tidak dapat diedit; hanya dapat diamandemen dengan alasan tercatat.

**Acceptance Criteria**

- Setiap encounter yang di-finish terkirim ke SATUSEHAT dalam < 5 menit (p95).
- Dashboard menampilkan jumlah encounter tertunda sinkron, dengan tombol retry manual.

---

### M4 — Anamnesis

**Deskripsi.** Pengiriman data anamnesis menggunakan kombinasi `Condition` (keluhan), `FamilyMemberHistory` (riwayat keluarga), `AllergyIntolerance` (alergi), dan `MedicationStatement` (obat yang sedang dikonsumsi).

**Requirement Fungsional**

- FR-M4.1 — Keluhan utama dicatat sebagai `Condition` dengan kategori keluhan (bukan diagnosis), status verifikasi `provisional` atau `unconfirmed`.
- FR-M4.2 — Riwayat penyakit sekarang dan dahulu sebagai teks terstruktur + `Condition` untuk yang terkodifikasi.
- FR-M4.3 — Alergi dicatat sebagai `AllergyIntolerance` dengan kategori (obat, makanan, lingkungan), tingkat kritikalitas, dan manifestasi.
- FR-M4.4 — **Alergi obat wajib memicu peringatan keras (hard stop) di modul peresepan.** Dokter harus memberikan alasan tertulis untuk melanjutkan.
- FR-M4.5 — Riwayat keluarga sebagai `FamilyMemberHistory` dengan hubungan keluarga terkodifikasi.
- FR-M4.6 — Obat yang sedang dikonsumsi sebagai `MedicationStatement`, dengan pencarian ke KFA.
- FR-M4.7 — Anamnesis dapat diisi bertahap oleh perawat (skrining awal) lalu dilengkapi dokter.
- FR-M4.8 — Template anamnesis per poli yang dapat dikustomisasi tenant, untuk mempercepat input.

**Acceptance Criteria**

- Alergi yang sudah tercatat muncul otomatis di setiap kunjungan berikutnya tanpa input ulang.
- Peringatan alergi obat muncul sebelum resep dapat disimpan, bukan setelahnya.

---

### M5 — Pemeriksaan Fisik & Tanda-Tanda Vital

**Deskripsi.** Semua hasil pengukuran dikirim sebagai `Observation`, dengan kode LOINC.

**Requirement Fungsional**

- FR-M5.1 — Input TTV standar: tekanan darah sistolik/diastolik, denyut nadi, laju pernapasan, suhu tubuh, saturasi oksigen.
- FR-M5.2 — Antropometri: berat badan, tinggi badan, lingkar perut, lingkar kepala (anak), dengan **BMI dihitung otomatis** dan dikirim sebagai Observation tersendiri.
- FR-M5.3 — Setiap Observation dipetakan ke kode LOINC yang sesuai. Mapping disimpan sebagai master data global, bukan hardcode.
- FR-M5.4 — Nilai di luar rentang normal ditandai visual (kuning = perhatian, merah = kritis) dengan rentang yang menyesuaikan usia pasien.
- FR-M5.5 — Pemeriksaan fisik per sistem organ (kepala-leher, thorax, abdomen, ekstremitas, neurologis) dengan pilihan cepat "dalam batas normal".
- FR-M5.6 — Grafik tren TTV lintas kunjungan pada satu layar riwayat pasien.
- FR-M5.7 — Untuk pasien anak: kurva pertumbuhan (WHO/Kemenkes) berdasarkan BB/TB/umur.

**Acceptance Criteria**

- Input lengkap TTV oleh perawat: < 45 detik.
- Semua Observation punya kode LOINC valid; sistem menolak simpan jika mapping tidak ditemukan (dengan fallback ke kode "lainnya" yang terdefinisi).

---

### M6 — Diagnosis

**Deskripsi.** Diagnosis dilaporkan dengan `Condition` menggunakan kode ICD-10. **Satu payload Condition hanya untuk satu kode ICD-10** — jika pasien punya dua diagnosis, kirim dua payload Condition terpisah.

**Requirement Fungsional**

- FR-M6.1 — Pencarian ICD-10 dengan autocomplete, mendukung pencarian bahasa Indonesia dan Inggris, serta pencarian by kode.
- FR-M6.2 — Sistem menyimpan **satu record Condition per kode ICD-10**, sesuai batasan SATUSEHAT.
- FR-M6.3 — Penandaan diagnosis primer vs sekunder menggunakan `rank`.
- FR-M6.4 — Kategori Condition: `encounter-diagnosis` untuk diagnosis kunjungan, `problem-list-item` untuk masalah kronis berkelanjutan.
- FR-M6.5 — Status klinis (`active`, `resolved`, `recurrence`) dan status verifikasi (`provisional`, `differential`, `confirmed`).
- FR-M6.6 — Riwayat "diagnosis favorit" per dokter — 20 ICD-10 paling sering dipakai, muncul di atas hasil pencarian.
- FR-M6.7 — Problem list pasien yang persisten lintas kunjungan.

**Acceptance Criteria**

- Dokter menemukan dan memilih kode ICD-10 dalam < 10 detik untuk 20 diagnosis tersering.
- Sistem tidak pernah mengirim dua kode ICD-10 dalam satu payload Condition.

---

### M7 — Tindakan Medis

**Requirement Fungsional**

- FR-M7.1 — Pencatatan tindakan sebagai `Procedure` dengan kode ICD-9-CM.
- FR-M7.2 — Setiap tindakan terhubung ke tarif di modul billing.
- FR-M7.3 — Katalog tindakan per tenant dengan tarif yang dapat dikonfigurasi (tarif umum, tarif BPJS, tarif asuransi).
- FR-M7.4 — Pencatatan pelaksana tindakan (bisa berbeda dari dokter pemeriksa) dan waktu pelaksanaan.
- FR-M7.5 — Informed consent digital untuk tindakan yang memerlukannya, tersimpan sebagai lampiran.

---

### M8 — Rencana & Kesimpulan Klinis

**Requirement Fungsional**

- FR-M8.1 — Riwayat perjalanan penyakit dan rasional klinis dikirim sebagai `ClinicalImpression`.
- FR-M8.2 — Rencana tata laksana sebagai `CarePlan` untuk kasus yang butuh follow-up terstruktur (kontrol rutin, program penyakit kronis).
- FR-M8.3 — Penjadwalan kontrol berikutnya dengan pengingat WhatsApp/SMS opsional.

---

### M9 — Farmasi & Resep Elektronik

**Deskripsi.** Data farmasi menggunakan `MedicationRequest` (peresepan), `Medication` (informasi obat, dikirim dalam elemen `contained`), `MedicationDispense` (pengeluaran obat), dan `QuestionnaireResponse` (pengkajian resep). Obat harus mengacu pada **Kamus Farmasi dan Alat Kesehatan (KFA)** yang dikeluarkan Kemenkes.

**Requirement Fungsional**

- FR-M9.1 — Master obat tenant wajib dipetakan ke kode KFA. Obat tanpa mapping KFA ditandai dan tidak dapat dikirim ke SATUSEHAT.
- FR-M9.2 — Sinkronisasi berkala master KFA dari API Kemenkes ke central database.
- FR-M9.3 — Peresepan elektronik: dokter memilih obat, dosis, frekuensi, durasi, aturan pakai, jumlah.
- FR-M9.4 — Perhitungan jumlah otomatis dari dosis × frekuensi × durasi.
- FR-M9.5 — Peringatan interaksi obat dasar dan duplikasi terapi.
- FR-M9.6 — Peringatan alergi obat (integrasi dengan M4) sebagai hard stop.
- FR-M9.7 — Dosis pediatri berbasis berat badan, dengan peringatan jika melebihi dosis maksimal.
- FR-M9.8 — Antrian resep di apotek: `diterima` → `dikaji` → `disiapkan` → `diserahkan`.
- FR-M9.9 — Pengkajian resep (administrasi, farmasetik, klinis) oleh apoteker, dikirim sebagai `QuestionnaireResponse`.
- FR-M9.10 — Dispense memotong stok otomatis. Stok tidak boleh minus.
- FR-M9.11 — Manajemen stok: batch, tanggal kedaluwarsa, FEFO (First Expired First Out), stok minimum dengan alert.
- FR-M9.12 — Pencatatan obat kedaluwarsa dan pemusnahan.
- FR-M9.13 — Racikan/compounding dengan komposisi multi-item.
- FR-M9.14 — Laporan narkotika & psikotropika (untuk klinik yang memilikinya).

**Acceptance Criteria**

- Apoteker menyelesaikan alur terima–kaji–dispense untuk resep 3 item dalam < 90 detik.
- Stok fisik dan stok sistem selisih < 1% dalam audit bulanan.
- 100% obat yang aktif digunakan sudah punya mapping KFA sebelum tenant naik ke production.

---

### M10 — Laboratorium

**Deskripsi.** Alur pemeriksaan penunjang laboratorium menggunakan `ServiceRequest` (permintaan), `Specimen` (spesimen), `Observation` (hasil per parameter), dan `DiagnosticReport` (laporan/kesimpulan). Jenis pemeriksaan direpresentasikan dengan kode LOINC.

**Requirement Fungsional**

- FR-M10.1 — Dokter membuat permintaan lab sebagai `ServiceRequest` dengan kode LOINC pemeriksaan.
- FR-M10.2 — Katalog pemeriksaan lab per tenant, dipetakan ke terminologi LOINC nasional. Gunakan parameter dengan kategori "Permintaan" atau "Permintaan & Hasil" saat pengiriman.
- FR-M10.3 — Pencatatan spesimen: jenis, waktu pengambilan, petugas, kondisi.
- FR-M10.4 — Input hasil per parameter sebagai `Observation` dengan nilai, satuan, dan rentang rujukan.
- FR-M10.5 — Dukungan nilai kualitatif (Positif/Negatif/Reaktif) dan kuantitatif.
- FR-M10.6 — Penandaan nilai kritis dengan notifikasi ke dokter pemeriksa.
- FR-M10.7 — Kesimpulan/interpretasi sebagai `DiagnosticReport`.
- FR-M10.8 — Cetak hasil lab dengan kop klinik dan tanda tangan penanggung jawab lab.
- FR-M10.9 — (v2) Integrasi LIS via HL7 v2 atau file watcher untuk alat analyzer.

**Acceptance Criteria**

- Hasil lab muncul otomatis di layar dokter tanpa perlu refresh manual.
- Semua parameter lab yang aktif punya mapping LOINC.

---

### M11 — Resume Medis

**Deskripsi.** Resume medis dikirim sebagai `Composition` — dokumen terstruktur yang merangkum satu episode pelayanan.

**Requirement Fungsional**

- FR-M11.1 — Generate resume medis otomatis dari data encounter (anamnesis, pemeriksaan, diagnosis, tindakan, terapi).
- FR-M11.2 — Resume dapat diedit dokter sebelum difinalisasi.
- FR-M11.3 — **Tanda tangan elektronik penanggung jawab pelayanan** — memenuhi Pasal 26 Permenkes 24/2022 yang mensyaratkan nama dan tanda tangan penanggung jawab pemberi pelayanan.
- FR-M11.4 — Setelah ditandatangani, dokumen terkunci. Perubahan hanya via addendum bertanda tangan terpisah.
- FR-M11.5 — Cetak/unduh PDF resume medis untuk pasien atau rujukan.

**Catatan implementasi tanda tangan.** Minimal v1: tanda tangan elektronik tidak tersertifikasi (nama + timestamp + hash konten + kredensial login). Roadmap v2: integrasi Penyelenggara Sertifikasi Elektronik (PSrE) tersertifikasi seperti BSrE untuk tanda tangan digital yang punya kekuatan hukum penuh.

---

### M12 — Rujukan

**Requirement Fungsional**

- FR-M12.1 — Pembuatan surat rujukan dengan tujuan faskes, alasan rujukan, diagnosis, dan ringkasan klinis.
- FR-M12.2 — Rujukan dikirim sebagai `ServiceRequest` dengan referensi ke Organization tujuan.
- FR-M12.3 — Integrasi rujukan BPJS via P-Care untuk pasien JKN (lihat M20).
- FR-M12.4 — Cetak surat rujukan sesuai format standar.

---

### M13 — Odontogram (Klinik Gigi)

**Deskripsi.** Modul Gigi SATUSEHAT memetakan pemeriksaan odontogram melalui resource `Observation`. Untuk data TTV dalam konteks gigi, pemetaannya merujuk ke modul pelayanan Rawat Jalan.

**Requirement Fungsional**

- FR-M13.1 — Odontogram interaktif dengan notasi FDI two-digit, mendukung gigi sulung dan permanen.
- FR-M13.2 — Pencatatan kondisi per permukaan gigi (mesial, distal, oklusal, bukal, lingual).
- FR-M13.3 — Setiap temuan odontogram dikirim sebagai `Observation` dengan kode terminologi yang sesuai.
- FR-M13.4 — Tindakan gigi sebagai `Procedure` dengan kode ICD-9-CM.
- FR-M13.5 — Riwayat odontogram: perbandingan kondisi antar kunjungan.
- FR-M13.6 — Cetak odontogram untuk rekam medis fisik dan klaim asuransi.

**Catatan.** Modul ini adalah pembeda komersial yang kuat — klinik gigi sering underserved oleh SIM klinik generik, dan mereka bersedia bayar lebih untuk odontogram yang benar.

---

### M14 — Imunisasi

**Requirement Fungsional**

- FR-M14.1 — Pencatatan imunisasi sebagai `Immunization` dengan vaksin terkodifikasi KFA.
- FR-M14.2 — Jadwal imunisasi dasar lengkap sesuai program nasional dengan pengingat otomatis.
- FR-M14.3 — Pencatatan batch/lot vaksin dan tanggal kedaluwarsa.
- FR-M14.4 — Pencatatan KIPI (Kejadian Ikutan Pasca Imunisasi).
- FR-M14.5 — Cetak kartu imunisasi anak.

---

### M15 — KIA (Antenatal & Postnatal Care)

**Requirement Fungsional**

- FR-M15.1 — Pemeriksaan antenatal dengan variabel standar (usia kehamilan, TFU, DJJ, presentasi janin, edema, hasil lab wajib).
- FR-M15.2 — Semua pengukuran ANC dikirim sebagai `Observation` sesuai modul Antenatal Care.
- FR-M15.3 — Deteksi risiko tinggi kehamilan dengan skoring dan penandaan otomatis.
- FR-M15.4 — Pemeriksaan nifas (PNC) dengan variabel standar.
- FR-M15.5 — Buku KIA digital: riwayat kehamilan lengkap dalam satu tampilan.

---

### M16 — Skrining PTM

**Requirement Fungsional**

- FR-M16.1 — Kuesioner skrining PTM sebagai `QuestionnaireResponse`.
- FR-M16.2 — Pengukuran faktor risiko (tekanan darah, gula darah, IMT, lingkar perut, status merokok) sebagai `Observation`.
- FR-M16.3 — Perhitungan skor risiko kardiovaskular dengan rekomendasi tindak lanjut.
- FR-M16.4 — Laporan agregat skrining untuk pelaporan ke Dinkes.

---

### M17 & M18 — MTBS dan Tuberkulosis

Ditunda ke fase 6. Relevan untuk klinik yang bekerja sama dengan program pemerintah.

- MTBS: klasifikasi berbasis algoritma, dikirim sebagai `Observation`, `Condition`, `CarePlan`.
- TB: data kasus dipetakan dengan `Observation`, `Immunization`, dan `CarePlan`.

---

### M19 — Billing & Kasir

**Requirement Fungsional**

- FR-M19.1 — Tagihan otomatis terbentuk dari tindakan, obat, lab, dan biaya administrasi.
- FR-M19.2 — Multi-tarif: umum, BPJS, asuransi, perusahaan (karyawan).
- FR-M19.3 — Metode pembayaran: tunai, kartu debit/kredit, QRIS, transfer.
- FR-M19.4 — Cetak struk dan kwitansi.
- FR-M19.5 — Laporan pendapatan harian, per dokter, per jenis layanan.
- FR-M19.6 — Penutupan kas harian dengan rekonsiliasi.
- FR-M19.7 — (Opsional, v2) Pengiriman `ChargeItem` dan `Claim` ke SATUSEHAT untuk klinik yang membutuhkan.

---

### M20 — Integrasi BPJS P-Care

**Catatan penting.** P-Care adalah API BPJS Kesehatan yang **terpisah** dari SATUSEHAT. Jangan dicampur dalam satu abstraksi.

**Requirement Fungsional**

- FR-M20.1 — Cek status kepesertaan by nomor kartu / NIK.
- FR-M20.2 — Pendaftaran kunjungan JKN ke P-Care.
- FR-M20.3 — Pengiriman diagnosis dan tindakan ke P-Care.
- FR-M20.4 — Pembuatan rujukan JKN.
- FR-M20.5 — Pencatatan kunjungan Prolanis untuk pasien kronis.
- FR-M20.6 — Antrean online terintegrasi Mobile JKN.

---

### M21 — Manajemen Pengguna & Hak Akses

**Deskripsi.** Permenkes 24/2022 mensyaratkan hak akses berjenjang terhadap rekam medis elektronik.

**Requirement Fungsional**

- FR-M21.1 — Role bawaan: `clinic_admin`, `registrar`, `nurse`, `practitioner`, `pharmacist`, `lab_staff`, `cashier`, `medical_record_officer`.
- FR-M21.2 — Permission granular berbasis modul + aksi (view, create, update, sign, export).
- FR-M21.3 — Role kustom per tenant.
- FR-M21.4 — Aturan akses klinis: dokter hanya dapat melihat rekam medis lengkap pasien yang sedang atau pernah ia layani. Akses ke pasien lain harus melalui *break-glass* dengan alasan tercatat.
- FR-M21.5 — Autentikasi dua faktor wajib untuk role `clinic_admin` dan opsional untuk role lain.
- FR-M21.6 — Kebijakan sesi: auto-logout setelah idle (default 15 menit, dapat dikonfigurasi).
- FR-M21.7 — Penonaktifan akun langsung mencabut semua sesi aktif.

---

### M22 — Audit Trail & Integritas Data

**Requirement Fungsional**

- FR-M22.1 — **Setiap akses baca terhadap rekam medis pasien tercatat**: siapa, kapan, pasien mana, dari IP mana. Ini bukan opsional — ini kebutuhan pembuktian saat sengketa.
- FR-M22.2 — Setiap perubahan data klinis tercatat dengan nilai sebelum dan sesudah.
- FR-M22.3 — Audit log bersifat append-only dan tidak dapat dihapus oleh siapa pun dari dalam aplikasi, termasuk `clinic_admin`.
- FR-M22.4 — Tidak ada hard delete pada data klinis. Koreksi dilakukan sebagai amandemen dengan alasan wajib diisi.
- FR-M22.5 — **Retensi minimal 25 tahun** sejak tanggal kunjungan terakhir pasien, sesuai Permenkes 24/2022. Ini mempengaruhi strategi arsip dan biaya penyimpanan jangka panjang — harus masuk model harga.
- FR-M22.6 — Backup otomatis harian dengan enkripsi at-rest, disimpan di lokasi terpisah dari server produksi.
- FR-M22.7 — Uji restore backup terjadwal setiap kuartal, terdokumentasi.
- FR-M22.8 — Ekspor lengkap data tenant dalam format terbuka (JSON FHIR + CSV) kapan pun diminta tenant. Ini kewajiban portabilitas data di bawah UU PDP dan sekaligus penghilang keberatan calon klien soal vendor lock-in.
- FR-M22.9 — Laporan audit siap cetak untuk keperluan inspeksi Dinkes.

---

### M23 — Admin Panel Vendor (Lapisan SaaS)

**Requirement Fungsional**

- FR-M23.1 — Provisioning tenant otomatis: buat database, jalankan migrasi, seed master data, buat akun admin pertama, kirim email undangan. Target: < 3 menit tanpa intervensi manual.
- FR-M23.2 — Dashboard kesehatan tenant: status sinkronisasi SATUSEHAT, jumlah job gagal, penggunaan storage, kunjungan bulanan.
- FR-M23.3 — Manajemen langganan: paket, siklus tagihan, status pembayaran, masa tenggang, suspensi.
- FR-M23.4 — **Suspensi karena tunggakan tidak boleh menghapus data atau memblokir akses baca rekam medis.** Mode suspensi = read-only. Memblokir akses total ke rekam medis pasien karena tagihan vendor adalah risiko hukum dan etika yang tidak boleh diambil.
- FR-M23.5 — Deployment migrasi ke semua tenant dengan rollout bertahap dan rollback.
- FR-M23.6 — Feature flag per tenant / per paket.
- FR-M23.7 — Break-glass access dengan approval tenant, time-boxed, dan notifikasi ke tenant admin.

---

## 8. Requirement Non-Fungsional

### 8.1 Performa

| Metrik | Target |
|---|---|
| Waktu muat halaman (p95) | < 1,5 detik |
| Pencarian pasien | < 500 ms |
| Simpan encounter | < 800 ms |
| Sinkronisasi ke SATUSEHAT (p95) | < 5 menit setelah encounter selesai |
| Kapasitas per tenant | 200 kunjungan/hari tanpa degradasi |
| Concurrent user per tenant | 25 |

### 8.2 Ketersediaan & Ketahanan

- Uptime target 99,5% pada jam operasional klinik (07.00–21.00 WIB).
- **Kegagalan SATUSEHAT tidak boleh menghentikan operasional klinik.** Semua pengiriman asinkron dengan antrian dan retry eksponensial.
- Degradasi anggun: jika SATUSEHAT down, sistem tetap melayani penuh dan menandai data sebagai `pending_sync`.
- RPO (Recovery Point Objective): 1 jam. RTO (Recovery Time Objective): 4 jam.

### 8.3 Keamanan

- Enkripsi in-transit (TLS 1.3) dan at-rest untuk database dan backup.
- Kredensial SATUSEHAT dan P-Care terenkripsi dengan kunci terpisah dari database.
- Rate limiting pada endpoint autentikasi.
- Kebijakan kata sandi dan rotasi.
- Penetration testing sebelum melewati 10 tenant.
- Kepatuhan UU PDP: dasar pemrosesan, consent, hak subjek data (akses, koreksi, portabilitas), notifikasi kebocoran dalam 72 jam.

### 8.4 Kepatuhan Legal — Perhatian Khusus

> **Ini adalah blocker komersial, bukan sekadar checklist.**

- **Pendaftaran PSE.** Permenkes 24/2022 Pasal 9 memperbolehkan fasyankes bekerja sama dengan vendor penyedia sistem informasi rekam medis, **dengan syarat vendor tersebut telah memiliki izin dan terdaftar sebagai Penyelenggara Sistem Elektronik (PSE) sektor kesehatan**. Selama produk masih dibangun custom untuk satu klien, posisinya berbeda. Begitu dijual sebagai produk SaaS ke banyak klinik, pendaftaran PSE menjadi wajib.
  - **Aksi:** mulai proses pendaftaran PSE **sebelum** menandatangani klien SaaS pertama, bukan sesudah. Ini bisa memakan waktu berminggu-minggu dan berpotensi memblokir peluncuran.
- **Perjanjian pemrosesan data.** Setiap kontrak tenant harus memuat klausul yang menegaskan klinik sebagai Pengendali Data dan kita sebagai Prosesor, dengan pembagian tanggung jawab yang jelas.
- **Lokasi server.** Simpan data di dalam wilayah Indonesia untuk menghindari komplikasi transfer data lintas negara.

### 8.5 Usability

- Antarmuka Bahasa Indonesia sepenuhnya, dengan istilah medis yang familiar bagi nakes Indonesia (bukan terjemahan literal dari istilah FHIR).
- Alur input yang dapat diselesaikan dengan keyboard saja untuk peran pendaftaran dan farmasi.
- Responsif untuk tablet (dokter sering memakai tablet di ruang periksa).
- Target: staf klinik baru dapat mengoperasikan modul pendaftaran setelah 30 menit pelatihan.

---

## 9. Model Bisnis SaaS

### 9.1 Struktur Paket (usulan awal)

| | **Pratama** | **Plus** | **Utama** |
|---|---|---|---|
| Target | Praktik mandiri, klinik kecil | Klinik pratama | Klinik utama / multi-poli |
| Nakes aktif | s/d 3 | s/d 10 | Tidak dibatasi |
| Kunjungan/bulan | s/d 500 | s/d 2.000 | Tidak dibatasi |
| RME + SATUSEHAT | ✓ | ✓ | ✓ |
| Farmasi & stok | ✓ | ✓ | ✓ |
| Billing & kasir | ✓ | ✓ | ✓ |
| Laboratorium | — | ✓ | ✓ |
| Odontogram | Add-on | ✓ | ✓ |
| BPJS P-Care | — | ✓ | ✓ |
| KIA, Imunisasi, PTM | — | ✓ | ✓ |
| Multi-cabang | — | — | ✓ |
| Dukungan | Email | Email + WhatsApp | Prioritas + onboarding onsite |

Harga belum ditetapkan — perlu riset kompetitor (Trustmedis, eHealth, Assist.id, Klinikpintar, Medifirst) sebelum penetapan.

### 9.2 Biaya yang Sering Diabaikan

- **Retensi 25 tahun.** Data tenant yang berhenti berlangganan tetap harus tersimpan. Model harga harus mengantisipasi biaya arsip jangka sangat panjang, atau kontrak harus mengatur serah terima arsip ke klinik saat terminasi.
- **Onboarding SATUSEHAT per tenant.** Meski otomatis, tetap butuh pendampingan 2–4 jam per klinik pertama kali.
- **Perubahan spesifikasi SATUSEHAT.** Portal dokumentasi SATUSEHAT terus diperbarui secara berkala; setiap versi baru berpotensi mengubah mapping. Alokasikan waktu pemeliharaan rutin.

### 9.3 Strategi Go-to-Market

**Fase 1 (klien 1–3): Design partner.** Bangun untuk klinik nyata dengan harga sangat rendah atau gratis, dengan imbalan akses penuh untuk observasi alur kerja dan hak menjadikan mereka studi kasus. Target: klinik di Makassar untuk kemudahan dukungan tatap muka.

**Fase 2 (klien 4–10): Validasi produk.** Harga penuh, tapi masih onboarding manual intensif. Fokus: membuktikan bahwa produk bisa dipasang tanpa kustomisasi kode.

**Fase 3 (klien 10+): Skala.** Self-service onboarding, dokumentasi, video tutorial. Ekspansi ke luar Sulawesi Selatan.

---

## 10. Roadmap Fase Pengembangan

| Fase | Cakupan | Kriteria Selesai |
|---|---|---|
| **F0 — Fondasi** | Setup `stancl/tenancy`, provisioning tenant otomatis, auth + RBAC, audit trail, admin panel vendor, suite test isolasi tenant | Tenant baru dapat di-provision otomatis < 3 menit; test isolasi tenant hijau |
| **F1 — Alur Inti** | M0, M1, M2, M3, M21, M22 | Klinik dapat mendaftarkan pasien, membuat antrian, dan mengirim Encounter ke SATUSEHAT staging |
| **F2 — Klinis Dasar** | M4, M5, M6, M7 | Satu kunjungan lengkap (anamnesis → diagnosis) terkirim penuh ke SATUSEHAT |
| **F3 — Farmasi & Uang** | M8, M9, M19 | Alur resep–dispense–bayar berjalan; stok akurat |
| **F4 — Penunjang & JKN** | M10, M11, M12, M20 | Klinik BPJS dapat beroperasi penuh |
| **F5 — Spesialisasi** | M13, M14, M15, M16 | Klinik gigi dan klinik dengan layanan KIA dapat dilayani |
| **F6 — Program Nasional** | M17, M18 | — |
| **F7 — Skala** | Self-service onboarding, dokumentasi, optimasi performa | Tenant dapat onboard tanpa kontak vendor |

**Rekomendasi urutan pengerjaan:** selesaikan F0–F2 dan jalankan dengan **satu klinik nyata** sebelum menyentuh F3. Sistem yang bagus di F1 tapi dipakai orang jauh lebih berharga daripada sistem lengkap di F5 yang belum pernah dipakai.

---

## 11. Metrik Keberhasilan

### 11.1 Metrik Produk

| Metrik | Target 6 bulan | Target 12 bulan |
|---|---|---|
| Tenant aktif berbayar | 3 | 12 |
| Tingkat keberhasilan sinkron SATUSEHAT | > 97% | > 99% |
| Encounter tertunda sinkron > 24 jam | < 2% | < 0,5% |
| Waktu onboarding tenant baru | < 7 hari | < 3 hari |
| Churn bulanan | — | < 3% |

### 11.2 Metrik Adopsi (per tenant)

- Persentase kunjungan yang dicatat di sistem vs total kunjungan klinik: target > 95% dalam 30 hari pasca go-live.
- Rata-rata durasi pencatatan per encounter oleh dokter: target < 90 detik.
- Persentase obat aktif yang sudah termapping KFA: 100% sebelum production.

---

## 12. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Pendaftaran PSE tertunda / ditolak | **Kritis** — memblokir penjualan SaaS | Mulai proses sedini mungkin; sementara itu, jual sebagai pengembangan custom per klien |
| Spesifikasi SATUSEHAT berubah | Sedang | Lapisan abstraksi mapping berbasis konfigurasi, bukan kode; pantau changelog portal dokumentasi |
| Mapping KFA tidak lengkap untuk obat racikan lokal | Sedang | Proses onboarding wajib memetakan seluruh formularium tenant sebelum production |
| Resistensi dokter terhadap input digital | **Tinggi** — penyebab kegagalan implementasi RME paling umum | Desain form yang lebih cepat dari kertas; template per poli; libatkan dokter sejak fase design partner |
| Job antrian berjalan di konteks tenant yang salah | **Kritis** — data klinik A terkirim dengan kredensial klinik B | Semua job wajib membawa identitas tenant; suite test isolasi tenant sebagai gerbang sebelum rilis |
| Habisnya `max_connections` MySQL saat tenant bertambah | Tinggi | Setel `pm.max_children` dan `max_connections` secara sadar; monitoring jumlah koneksi aktif sebagai metrik utama |
| Satu developer = single point of failure | **Tinggi** | Dokumentasi arsitektur, infrastruktur as code, dan rencana kontinuitas kalau developer tidak tersedia |
| Insiden kebocoran data | **Kritis** | Enkripsi, audit, pentest, asuransi siber, prosedur notifikasi 72 jam |
| Kompetitor mapan dengan modal besar | Sedang | Fokus segmen yang underserved (klinik gigi, klinik kecil di luar Jawa); layanan personal sebagai pembeda |

---

## 13. Pertanyaan Terbuka

1. **Segmen fokus pertama:** klinik umum atau klinik gigi? Odontogram butuh usaha ekstra tapi kompetisinya jauh lebih tipis.
2. **Model peluncuran:** kejar pendaftaran PSE lebih dulu (lambat tapi aman), atau mulai dengan model custom-per-klien sambil memproses PSE?
3. **Hosting:** VPS sendiri (Biznet Neo) atau cloud terkelola? Retensi 25 tahun dan kewajiban keamanan membuat ini keputusan jangka sangat panjang.
4. **Tanda tangan elektronik:** cukup non-tersertifikasi untuk v1, atau langsung integrasi PSrE?
5. **Migrasi data legacy:** apakah menyediakan layanan migrasi dari sistem lama / Excel klinik? Ini sering jadi penentu keputusan pembelian.
6. **Dukungan offline:** apakah klinik target punya internet yang cukup andal? Kalau tidak, butuh mode offline-first yang menambah kompleksitas signifikan.

---

## 14. Lampiran — Referensi

**Regulasi**
- Permenkes No. 24 Tahun 2022 tentang Rekam Medis
- UU No. 17 Tahun 2023 tentang Kesehatan
- UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi

**Dokumentasi Teknis**
- Portal Dokumentasi SATUSEHAT — `satusehat.kemkes.go.id/platform/docs/id/`
- Playbook Modul Resume Medis Rawat Jalan
- Playbook Modul Pelayanan Kefarmasian
- Playbook Modul Gigi
- Kamus Farmasi dan Alat Kesehatan (KFA)
- Lampiran Terminologi Laboratorium & Radiologi (LOINC)
- HL7 FHIR R4 Specification

**Catatan:** portal dokumentasi SATUSEHAT diperbarui secara berkala. Verifikasi mapping resource terhadap versi terbaru sebelum implementasi tiap modul.
