<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TenantContextMismatchException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;

/**
 * Induk setiap job yang bekerja atas data SATU klinik.
 *
 * Risiko yang dijaga (PRD §12, "kritis"): job yang berjalan di konteks tenant
 * yang salah membaca data pasien klinik A dan mengirimkannya dengan kredensial
 * SATUSEHAT klinik B. Worker antrian adalah satu proses panjang yang
 * berganti-ganti tenant; "database yang terakhir aktif" bukan konteks yang
 * boleh diandalkan.
 *
 * Tiga jaminan:
 *
 * 1. Tenant pemilik ditangkap SAAT JOB DISERIALISASI, bukan di konstruktor —
 *    subkelas tidak perlu ingat memanggil parent::__construct(). Men-dispatch
 *    dari konteks pusat langsung gagal.
 *
 * 2. Tenancy di worker diinisialisasi QueueTenancyBootstrapper dari payload,
 *    SEBELUM model di-unserialize — sehingga `User $user` di properti job
 *    di-restore dari database klinik yang benar.
 *
 * 3. Sebelum handleForTenant() berjalan, konteks aktif diverifikasi sama dengan
 *    tenant pemilik. Kalau tidak, job gagal keras tanpa efek samping — tidak
 *    ada "coba pindahkan saja konteksnya", karena model di properti job
 *    mungkin sudah di-restore dari database yang salah.
 *
 * Subkelas mengimplementasikan `handleForTenant()`; dependensinya bisa
 * disuntikkan lewat parameter method seperti handle() biasa.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        __serialize as serializeModels;
    }

    /** Diisi otomatis. Publik supaya ikut terserialisasi dan terlihat di Horizon. */
    public ?string $tenantId = null;

    public function __serialize(): array
    {
        if ($this->tenantId === null) {
            if (! tenancy()->initialized) {
                throw new LogicException(static::class.' bekerja atas data klinik dan harus di-dispatch dari dalam konteks tenant.');
            }

            $this->tenantId = (string) tenant()->getTenantKey();
        }

        return $this->serializeModels();
    }

    final public function handle(Container $container): void
    {
        $current = tenancy()->initialized ? (string) tenant()->getTenantKey() : null;

        // dispatchSync() tidak menyeberangi batas serialisasi: job berjalan
        // di proses dan konteks pemanggil, jadi konteks itulah pemiliknya.
        $this->tenantId ??= $current;

        if ($this->tenantId === null || $current !== $this->tenantId) {
            throw new TenantContextMismatchException(static::class, $this->tenantId, $current);
        }

        $container->call([$this, 'handleForTenant']);
    }
}
