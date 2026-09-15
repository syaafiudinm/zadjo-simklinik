<?php

declare(strict_types=1);

return [
    /*
    | Auto-logout setelah idle (FR-M21.6), dalam menit. Nilai bawaan untuk
    | semua klinik; tiap klinik bisa menimpanya lewat tenant_settings dengan
    | kunci `session.idle_timeout_minutes`.
    */
    'idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT', 15),

    /*
    | Batas bawah dan atas yang boleh dipilih klinik. Batas atas mencegah
    | klinik mematikan fitur ini dengan angka raksasa; komputer di meja
    | pendaftaran sering ditinggal dalam keadaan login.
    */
    'idle_timeout_bounds' => [5, 120],

    /*
    | Hak user MySQL runtime tenant, per tabel (lihat TenantDatabaseGrants).
    |
    | User runtime tidak pernah memegang hak DDL. Tabel yang tidak disebut
    | mendapat `default`. Tabel klinis yang dibangun di Sprint 2 WAJIB didaftarkan
    | tanpa DELETE — hard delete data klinis dilarang (FR-M22.4), dan larangan
    | itu harus berlaku juga untuk query yang melewati Eloquent.
    */
    'database_grants' => [
        'default' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'tables' => [
            // Append-only (FR-M22.3). Tidak bisa diubah atau dihapus dari
            // dalam aplikasi, oleh siapa pun, termasuk lewat tinker.
            'audit_logs' => ['SELECT', 'INSERT'],
            // Riwayat migrasi hanya ditulis koneksi migrator.
            'migrations' => ['SELECT'],
        ],
    ],

    'audit' => [
        /*
        | Akses baca rekam medis yang sama oleh orang yang sama dalam jendela
        | ini dicatat sekali. Mencegah satu halaman yang di-refresh atau
        | di-prefetch menulis puluhan baris, tanpa kehilangan fakta aksesnya.
        */
        'access_dedup_seconds' => (int) env('AUDIT_ACCESS_DEDUP_SECONDS', 60),

        // Nilai kolom-kolom ini tidak pernah ditulis ke log audit.
        'masked_attributes' => ['password', 'remember_token', 'db_password', 'token'],

        // Panjang minimum alasan amandemen data klinis (FR-M22.4).
        'amendment_reason_min_length' => 10,
    ],
];
