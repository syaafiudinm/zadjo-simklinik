<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Konfigurasi per klinik yang harus terbaca dari konteks pusat — mis. saat
 * provisioning atau saat panel vendor menampilkan paket tenant — sehingga
 * tinggal di database central, bukan di database tenant.
 *
 * Preferensi yang hanya relevan di dalam aplikasi klinik (format nomor rekam
 * medis, prefix antrian per poli) nanti tinggal di database tenant.
 */
class TenantSetting extends Model
{
    use CentralConnection;

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
