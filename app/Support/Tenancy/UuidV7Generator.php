<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * ID tenant memakai UUID v7, bukan v4.
 *
 * UUID v7 memuat timestamp di bit paling depan sehingga berurutan secara
 * leksikografis. Untuk InnoDB itu berarti insert selalu di ujung indeks primer
 * alih-alih menyebar acak — perbedaan yang tidak terasa di tabel `tenants`
 * yang kecil, tapi menjadi kebiasaan yang benar untuk tabel klinis bervolume
 * tinggi di Sprint 2 (lihat PRD §5.3, "ID internal").
 */
class UuidV7Generator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        return (string) Str::uuid7();
    }
}
