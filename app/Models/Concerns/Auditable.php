<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat create/update/delete model ke jejak audit, dengan nilai sebelum dan
 * sesudah (FR-M22.2).
 *
 * Batasnya perlu diketahui: event model tidak berbunyi untuk `saveQuietly()`,
 * `Model::withoutEvents()`, maupun query builder massal (`->update([...])`).
 * Untuk data klinis, celah terakhir itu ditutup ClinicalModel + hak MySQL;
 * dua yang pertama adalah pilihan sadar pemanggil dan harus terlihat di review.
 *
 * @mixin Model
 */
trait Auditable
{
    /** Alasan amandemen yang sedang berlangsung; diisi ClinicalModel::amend(). */
    protected ?string $pendingAmendmentReason = null;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            $model->writeAudit(AuditEvent::Created, [], $model->auditableValues($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            $changes = $model->auditableValues($model->getChanges());

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);

            $model->writeAudit(
                $model->pendingAmendmentReason !== null ? AuditEvent::Amended : AuditEvent::Updated,
                $old,
                $changes,
                $model->pendingAmendmentReason,
            );
        });

        static::deleted(function (Model $model) {
            $model->writeAudit(AuditEvent::Deleted, $model->auditableValues($model->getAttributes()), []);
        });
    }

    /**
     * Atribut yang tidak dicatat sama sekali — perubahannya tidak bermakna
     * untuk audit dan hanya menambah kebisingan. Nilai rahasia (password)
     * TETAP dicatat sebagai "berubah", tapi nilainya disamarkan AuditLogger.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return ['created_at', 'updated_at'];
    }

    public function auditLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableValues(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExcludedAttributes()));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function writeAudit(AuditEvent $event, array $old, array $new, ?string $reason = null): void
    {
        app(AuditLogger::class)->record($event, $this, $old, $new, $reason);
    }
}
