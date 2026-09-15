<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit layanan di dalam klinik (Poli Umum, Poli Gigi, ...). Hidup di database
 * tenant. Diaudit karena perubahan poli memengaruhi antrian dan pemetaan
 * lokasi SATUSEHAT.
 */
class Polyclinic extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'queue_prefix', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
