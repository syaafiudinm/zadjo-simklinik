<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Exceptions\HardDeleteForbiddenException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menutup jalan hapus massal lewat query builder Eloquent:
 * `Diagnosis::where(...)->delete()` tidak memicu event model, jadi larangan di
 * level model saja tidak cukup.
 *
 * @template TModel of \App\Models\ClinicalModel
 *
 * @extends Builder<TModel>
 */
class ClinicalBuilder extends Builder
{
    public function delete()
    {
        throw HardDeleteForbiddenException::for($this->model::class);
    }

    public function forceDelete()
    {
        throw HardDeleteForbiddenException::for($this->model::class);
    }

    public function truncate(): void
    {
        throw HardDeleteForbiddenException::for($this->model::class);
    }
}
