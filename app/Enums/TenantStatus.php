<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Siklus hidup tenant.
 *
 * Catatan kepatuhan (PRD FR-M23.4): tidak ada status yang memblokir akses baca
 * rekam medis. Tunggakan tagihan menurunkan tenant ke `read_only`, tidak pernah
 * mematikannya — menutup akses rekam medis pasien karena urusan tagihan vendor
 * adalah risiko hukum yang tidak boleh diambil.
 */
enum TenantStatus: string
{
    /** Database sedang dibuat; belum boleh menerima trafik. */
    case Provisioning = 'provisioning';

    /** Operasional penuh. */
    case Active = 'active';

    /** Bisa dibaca, tulis diblokir. Dipakai untuk tunggakan dan masa migrasi. */
    case ReadOnly = 'read_only';

    /** Akses aplikasi dihentikan sementara. Data tetap utuh dan tetap dibackup. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Sedang disiapkan',
            self::Active => 'Aktif',
            self::ReadOnly => 'Hanya baca',
            self::Suspended => 'Ditangguhkan',
        };
    }

    /** Apakah tenant boleh melayani request sama sekali. */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Active, self::ReadOnly], true);
    }

    /** Apakah tenant boleh menerima perubahan data. */
    public function allowsWrites(): bool
    {
        return $this === self::Active;
    }
}
