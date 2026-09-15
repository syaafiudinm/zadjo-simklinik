<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use InvalidArgumentException;

/**
 * Satu-satunya sumber kebenaran untuk permission dan role bawaan.
 *
 * Permission memakai pola `modul.aksi` (FR-M21.2). Aksi didefinisikan PER
 * MODUL, bukan perkalian semua modul × semua aksi: `queue.sign` atau
 * `inventory.break_glass` tidak bermakna apa-apa, dan permission yang tidak
 * bermakna tetap muncul di layar pengaturan role dan membingungkan admin klinik.
 *
 * Modul yang belum dibangun sengaja sudah ada di sini (catatan Sprint 1 S1-06):
 * menambahkannya sekarang di seeder awal jauh lebih murah daripada migrasi
 * tambal-sulam ke 50 database tenant nanti.
 */
final class PermissionCatalog
{
    public const GUARD = 'web';

    /**
     * @var array<string, array{label: string, actions: list<string>}>
     */
    public const MODULES = [
        // Identitas & pendaftaran (M1, M2)
        'patient' => ['label' => 'Pasien', 'actions' => ['view', 'create', 'update', 'export', 'merge']],
        'consent' => ['label' => 'Persetujuan pasien', 'actions' => ['view', 'create']],
        'queue' => ['label' => 'Antrian', 'actions' => ['view', 'create', 'update']],

        // Pelayanan klinis (M3–M8)
        'encounter' => ['label' => 'Kunjungan', 'actions' => ['view', 'create', 'update', 'sign']],
        'anamnesis' => ['label' => 'Anamnesis', 'actions' => ['view', 'create', 'update']],
        'vital_sign' => ['label' => 'Tanda vital & pemeriksaan fisik', 'actions' => ['view', 'create', 'update']],
        'diagnosis' => ['label' => 'Diagnosis', 'actions' => ['view', 'create', 'update']],
        'procedure' => ['label' => 'Tindakan', 'actions' => ['view', 'create', 'update']],
        'clinical_plan' => ['label' => 'Rencana klinis', 'actions' => ['view', 'create', 'update']],

        // Farmasi (M9)
        'prescription' => ['label' => 'Resep', 'actions' => ['view', 'create', 'update', 'sign']],
        'dispense' => ['label' => 'Pelayanan resep', 'actions' => ['view', 'create', 'update']],
        'inventory' => ['label' => 'Stok obat', 'actions' => ['view', 'create', 'update', 'export']],

        // Penunjang & dokumen (M10–M12)
        'lab_order' => ['label' => 'Permintaan lab', 'actions' => ['view', 'create']],
        'lab_result' => ['label' => 'Hasil lab', 'actions' => ['view', 'create', 'update', 'sign']],
        'medical_resume' => ['label' => 'Resume medis', 'actions' => ['view', 'create', 'update', 'sign', 'export']],
        'referral' => ['label' => 'Rujukan', 'actions' => ['view', 'create', 'sign', 'export']],

        // Layanan spesialis & program (M13–M16)
        'odontogram' => ['label' => 'Odontogram', 'actions' => ['view', 'create', 'update']],
        'immunization' => ['label' => 'Imunisasi', 'actions' => ['view', 'create', 'update']],
        'kia' => ['label' => 'KIA', 'actions' => ['view', 'create', 'update']],
        'ptm_screening' => ['label' => 'Skrining PTM', 'actions' => ['view', 'create', 'update']],

        // Keuangan & JKN (M19, M20)
        'billing' => ['label' => 'Tagihan', 'actions' => ['view', 'create', 'update', 'export']],
        'cash_closing' => ['label' => 'Tutup kas', 'actions' => ['view', 'create']],
        'bpjs' => ['label' => 'BPJS P-Care', 'actions' => ['view', 'create']],

        // Administrasi & kepatuhan (M0, M21, M22)
        'satusehat' => ['label' => 'SATUSEHAT', 'actions' => ['view', 'update', 'sync']],
        'report' => ['label' => 'Laporan', 'actions' => ['view', 'export']],
        'user' => ['label' => 'Pengguna', 'actions' => ['view', 'create', 'update', 'deactivate']],
        'role' => ['label' => 'Peran & hak akses', 'actions' => ['view', 'create', 'update']],
        'audit_log' => ['label' => 'Log audit', 'actions' => ['view', 'export']],
        'clinic_setting' => ['label' => 'Pengaturan klinik', 'actions' => ['view', 'update']],

        // FR-M21.4 — membuka rekam medis pasien yang tidak sedang/pernah
        // dilayani. Aksesnya wajib beralasan dan tercatat (S1-07).
        'medical_record' => ['label' => 'Rekam medis (break-glass)', 'actions' => ['break_glass']],
    ];

    /**
     * Role bawaan (FR-M21.1). `*` berarti semua aksi yang didefinisikan modul itu.
     *
     * Catatan desain `clinic_admin`: SENGAJA tidak mendapat akses rekam medis.
     * Mengelola klinik tidak sama dengan berhak membaca diagnosis pasien —
     * prinsip minimisasi akses UU PDP. Pemilik klinik yang juga berpraktik
     * cukup diberi dua role: `clinic_admin` dan `practitioner`.
     *
     * @var array<string, array{label: string, permissions: list<string>}>
     */
    public const ROLES = [
        'clinic_admin' => [
            'label' => 'Admin Klinik',
            'permissions' => [
                'user.*', 'role.*', 'audit_log.*', 'clinic_setting.*', 'satusehat.*', 'report.*',
                'billing.view', 'cash_closing.view', 'inventory.view',
            ],
        ],
        'registrar' => [
            'label' => 'Petugas Pendaftaran',
            'permissions' => ['patient.view', 'patient.create', 'patient.update', 'consent.*', 'queue.*', 'bpjs.*'],
        ],
        'nurse' => [
            'label' => 'Perawat',
            'permissions' => [
                'patient.view', 'queue.view', 'queue.update', 'encounter.view',
                'anamnesis.*', 'vital_sign.*', 'kia.*', 'immunization.*', 'ptm_screening.*',
                'lab_order.view', 'lab_result.view', 'medical_resume.view',
            ],
        ],
        'practitioner' => [
            'label' => 'Dokter',
            'permissions' => [
                'patient.view', 'queue.view', 'queue.update',
                'encounter.*', 'anamnesis.*', 'vital_sign.*', 'diagnosis.*', 'procedure.*', 'clinical_plan.*',
                'prescription.*', 'lab_order.*', 'lab_result.view', 'medical_resume.*', 'referral.*',
                'odontogram.*', 'immunization.*', 'kia.*', 'ptm_screening.*',
                'medical_record.break_glass',
            ],
        ],
        'pharmacist' => [
            'label' => 'Apoteker',
            'permissions' => ['patient.view', 'prescription.view', 'dispense.*', 'inventory.*'],
        ],
        'lab_staff' => [
            'label' => 'Petugas Laboratorium',
            'permissions' => ['patient.view', 'lab_order.view', 'lab_result.*'],
        ],
        'cashier' => [
            'label' => 'Kasir',
            'permissions' => ['patient.view', 'billing.*', 'cash_closing.*'],
        ],
        'medical_record_officer' => [
            'label' => 'Petugas Rekam Medis',
            'permissions' => [
                'patient.*', 'encounter.view', 'diagnosis.view', 'procedure.view',
                'medical_resume.view', 'medical_resume.export', 'referral.view', 'referral.export',
                'audit_log.view', 'report.*',
            ],
        ],
    ];

    /**
     * Modul yang memberi kuasa ATAS sistem itu sendiri — siapa boleh masuk,
     * dengan hak apa, dan jejak siapa berbuat apa.
     *
     * Dipakai aturan anti-eskalasi di RoleAssignment: seseorang hanya boleh
     * memberikan role yang permission administratifnya sudah ia miliki
     * sendiri. Permission klinis sengaja tidak termasuk; admin klinik memang
     * perlu menugaskan dokter tanpa dirinya menjadi dokter.
     *
     * `medical_record.break_glass` juga TIDAK termasuk, walau terdengar
     * berkuasa: ia kewenangan klinis darurat milik dokter yang setiap
     * pemakaiannya wajib beralasan dan tercatat (S1-07). Memasukkannya ke sini
     * membuat admin klinik tidak bisa membuat akun dokter sama sekali.
     *
     * @var list<string>
     */
    public const PRIVILEGED_MODULES = ['user', 'role', 'audit_log', 'clinic_setting', 'satusehat'];

    /** @return list<string> Semua nama permission, terurut. */
    public static function permissions(): array
    {
        $names = [];

        foreach (self::MODULES as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    public static function isBuiltInRole(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role]['label'] ?? $role;
    }

    /**
     * Permission milik role bawaan, dengan `modul.*` sudah dijabarkan.
     *
     * Pola yang tidak cocok dengan katalog melempar exception, bukan diam-diam
     * diabaikan: salah ketik `precription.*` akan membuat dokter tanpa akses
     * resep, dan itu harus gagal saat test, bukan saat klinik buka.
     *
     * @return list<string>
     */
    public static function permissionsForRole(string $role): array
    {
        if (! self::isBuiltInRole($role)) {
            throw new InvalidArgumentException("Role bawaan [{$role}] tidak dikenal.");
        }

        $all = self::permissions();
        $resolved = [];

        foreach (self::ROLES[$role]['permissions'] as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                $module = substr($pattern, 0, -2);

                if (! array_key_exists($module, self::MODULES)) {
                    throw new InvalidArgumentException("Modul [{$module}] pada role [{$role}] tidak ada di katalog.");
                }

                foreach (self::MODULES[$module]['actions'] as $action) {
                    $resolved[] = "{$module}.{$action}";
                }

                continue;
            }

            if (! in_array($pattern, $all, true)) {
                throw new InvalidArgumentException("Permission [{$pattern}] pada role [{$role}] tidak ada di katalog.");
            }

            $resolved[] = $pattern;
        }

        $resolved = array_values(array_unique($resolved));
        sort($resolved);

        return $resolved;
    }
}
