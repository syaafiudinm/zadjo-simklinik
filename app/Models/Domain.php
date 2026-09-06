<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * Karena identifikasi tenant memakai subdomain, kolom `domain` menyimpan
 * fragmen subdomain saja (`klinik-melati`), bukan hostname penuh — itulah yang
 * dicari `DomainTenantResolver` saat `InitializeTenancyBySubdomain` berjalan.
 *
 * Tabelnya tetap menyimpan domain penuh nanti kalau ada tenant yang memakai
 * domain sendiri (mis. `rme.klinikmelati.co.id`); resolusinya tinggal berganti
 * ke `InitializeTenancyByDomainOrSubdomain` tanpa mengubah skema.
 */
class Domain extends BaseDomain
{
    protected $guarded = [];
}
