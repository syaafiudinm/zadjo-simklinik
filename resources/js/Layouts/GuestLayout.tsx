import { usePage } from "@inertiajs/react";
import type { PropsWithChildren } from "react";
import type { SharedProps } from "@/types";

export default function GuestLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { tenant } = usePage<SharedProps>().props;

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-6 py-12">
            <div className="w-full max-w-sm">
                <p className="text-center text-sm font-medium text-slate-500">{tenant?.name ?? "SIMKlinik"}</p>
                <h1 className="mt-1 text-center text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>

                {tenant?.readOnly && (
                    <p className="mt-4 rounded-md bg-amber-100 px-3 py-2 text-center text-xs text-amber-900">
                        Klinik dalam mode hanya-baca. Anda tetap dapat masuk untuk membuka rekam medis.
                    </p>
                )}

                <div className="mt-8 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">{children}</div>
            </div>
        </div>
    );
}
