<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

class HardDeleteForbiddenException extends LogicException
{
    public static function for(string $model): self
    {
        return new self(
            "Data klinis [{$model}] tidak boleh dihapus (FR-M22.4). Koreksi dilakukan lewat amend() dengan alasan tertulis."
        );
    }
}
