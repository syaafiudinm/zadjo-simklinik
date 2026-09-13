<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unit layanan di dalam klinik (Poli Umum, Poli Gigi, ...). Hidup di database
 * tenant.
 */
class Polyclinic extends Model
{
    protected $fillable = ['code', 'name', 'queue_prefix', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
