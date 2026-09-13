<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Satu baris jejak audit. Hidup di database tenant.
 *
 * Penolakan update/delete di sini hanya lapis pertama yang memberi pesan
 * jelas. Penegak sesungguhnya adalah hak MySQL: user runtime tidak memegang
 * UPDATE maupun DELETE atas tabel ini, jadi query yang melewati model pun
 * tetap ditolak database.
 *
 * @property AuditEvent $event
 * @property \Carbon\CarbonImmutable $occurred_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'occurred_at' => 'immutable_datetime',
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Log audit bersifat append-only dan tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Log audit bersifat append-only dan tidak dapat dihapus.'));
    }
}
