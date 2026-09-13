<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validasi slug tenant — yang sekaligus menjadi subdomain dan nama database.
 *
 * Pola regex di sini dipakai juga oleh constraint rute tenant
 * (routes/tenant.php). Satu sumber, supaya tidak pernah ada slug yang lolos
 * validasi tapi tidak bisa dicocokkan rute, atau sebaliknya.
 */
class TenantSlug implements ValidationRule
{
    /** Label DNS: huruf kecil, angka, tanda hubung; tidak diawali/diakhiri tanda hubung. */
    public const PATTERN = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

    /**
     * Nama database = prefix + slug. MySQL membatasi nama database 64
     * karakter; 40 menyisakan ruang cukup untuk prefix dan suffix.
     */
    public const MAX_LENGTH = 40;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Slug klinik wajib diisi.');

            return;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $fail('Slug klinik maksimal '.self::MAX_LENGTH.' karakter.');

            return;
        }

        if (! preg_match('/^'.self::PATTERN.'$/', $value)) {
            $fail('Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung, serta tidak diawali atau diakhiri tanda hubung.');

            return;
        }

        if (in_array($value, (array) config('tenancy.reserved_subdomains'), true)) {
            $fail("Slug [{$value}] dipakai oleh aplikasi pusat dan tidak dapat digunakan.");
        }
    }
}
