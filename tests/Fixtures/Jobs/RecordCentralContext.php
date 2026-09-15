<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Job pusat tiruan: mencatat apakah ia berjalan dengan tenancy aktif. */
class RecordCentralContext implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?bool $tenancyWasInitialized = null;

    public function handle(): void
    {
        static::$tenancyWasInitialized = tenancy()->initialized;
    }
}
