<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Jobs\TenantAwareJob;
use App\Models\Polyclinic;

/**
 * Job tenant tiruan yang MENULIS ke database tenant, lewat model yang
 * di-restore dari payload — persis pola job SATUSEHAT di Sprint 2.
 */
class RenamePolyclinic extends TenantAwareJob
{
    /** @var list<string> */
    public static array $ranIn = [];

    public function __construct(public Polyclinic $polyclinic, public string $name) {}

    public function handleForTenant(): void
    {
        static::$ranIn[] = (string) tenant('slug');

        $this->polyclinic->update(['name' => $this->name]);
    }
}
