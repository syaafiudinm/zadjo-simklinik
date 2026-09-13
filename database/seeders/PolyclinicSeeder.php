<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Polyclinic;
use Illuminate\Database\Seeder;

/**
 * Poli default untuk klinik baru. Klinik bebas menonaktifkan atau menambah
 * poli sesudahnya; seeder ini tidak menimpa poli yang sudah diubah.
 */
class PolyclinicSeeder extends Seeder
{
    public function run(): void
    {
        $default = [
            ['UMUM', 'Poli Umum', 'A'],
            ['GIGI', 'Poli Gigi', 'G'],
            ['KIA', 'Poli KIA', 'K'],
        ];

        foreach ($default as [$code, $name, $prefix]) {
            Polyclinic::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'queue_prefix' => $prefix, 'is_active' => true],
            );
        }
    }
}
