import { usePage } from "@inertiajs/react";
import type { PropsWithChildren, ReactNode } from "react";
import type { SharedProps } from "@/types";

/**
 * Kerangka halaman untuk konteks pusat maupun tenant.
 *
 * Banner hanya-baca dipasang di sini, bukan di masing-masing halaman: satu
 * halaman yang lupa memasangnya akan membuat petugas mengira datanya tersimpan.
 */
export default function AppShell({
    title,
    subtitle,
    children,
}: PropsWithChildren<{ title: string; subtitle?: ReactNode }>) {
    const { tenant } = usePage<SharedProps>().props;

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            {tenant?.readOnly && (
                <div className="bg-amber-100 px-4 py-2.5 text-center text-sm text-amber-900">
                    <strong className="font-semibold">Mode hanya-baca.</strong>{" "}
                    Rekam medis tetap dapat dibuka, tetapi perubahan baru tidak
                    dapat disimpan. Hubungi administrator klinik.
                </div>
            )}

            <main className="mx-auto max-w-3xl px-6 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                {subtitle && (
                    <p className="mt-2 text-sm text-slate-600">{subtitle}</p>
                )}
                <div className="mt-10">{children}</div>
            </main>
        </div>
    );
}
