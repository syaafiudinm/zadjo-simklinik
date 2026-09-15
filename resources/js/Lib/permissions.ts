import { usePage } from "@inertiajs/react";
import type { SharedProps } from "@/types";

/**
 * `const can = useCan(); can("patient.view")`
 *
 * Hanya untuk keputusan TAMPILAN — menyembunyikan menu dan tombol yang pasti
 * ditolak. Menyembunyikan tombol bukan otorisasi: setiap rute dan aksi
 * diperiksa ulang di server lewat middleware `permission:` dan policy.
 */
export function useCan() {
    const { auth } = usePage<SharedProps>().props;
    const granted = new Set(auth.permissions);

    return (permission: string) => granted.has(permission);
}
