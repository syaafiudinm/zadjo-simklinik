<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\HardDeleteForbiddenException;
use App\Models\Builders\ClinicalBuilder;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Induk semua model data klinis (Sprint 2: pasien, kunjungan, diagnosis, …).
 *
 * Tiga lapis larangan hard delete (FR-M22.4):
 *  1. `delete()` / `destroy()` pada model  → exception
 *  2. `->delete()` / `->truncate()` pada query builder Eloquent → exception
 *  3. hak MySQL: tabel klinis wajib terdaftar di
 *     config/simklinik.php → database_grants TANPA DELETE → query mentah
 *     lewat DB::table() pun ditolak database
 *
 * Lapis 3 dijaga test (tests/Feature/AuditTrailTest): setiap subkelas model
 * ini yang tabelnya masih boleh DELETE membuat suite gagal.
 *
 * Koreksi data dilakukan dengan amend(), yang mewajibkan alasan tertulis dan
 * mencatat nilai sebelum/sesudah ke jejak audit.
 */
abstract class ClinicalModel extends Model
{
    use Auditable;

    public function delete(): never
    {
        throw HardDeleteForbiddenException::for(static::class);
    }

    public function forceDelete(): never
    {
        throw HardDeleteForbiddenException::for(static::class);
    }

    public static function destroy($ids): never
    {
        throw HardDeleteForbiddenException::for(static::class);
    }

    /**
     * Mengoreksi data klinis dengan alasan yang wajib dan tercatat.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function amend(array $attributes, string $reason): bool
    {
        $reason = trim($reason);
        $min = (int) config('simklinik.audit.amendment_reason_min_length');

        if (mb_strlen($reason) < $min) {
            throw new InvalidArgumentException("Alasan amandemen wajib diisi, minimal {$min} karakter.");
        }

        $this->fill($attributes);

        if (! $this->isDirty()) {
            return false;
        }

        $this->pendingAmendmentReason = $reason;

        try {
            return $this->save();
        } finally {
            $this->pendingAmendmentReason = null;
        }
    }

    public function newEloquentBuilder($query): ClinicalBuilder
    {
        return new ClinicalBuilder($query);
    }
}
