import { Head } from "@inertiajs/react";

export default function TenantProvisioning({
    tenantName,
}: {
    tenantName?: string;
}) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
            <Head title="Sedang disiapkan" />
            <div className="max-w-md text-center">
                <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
                    Sedang disiapkan
                </h1>
                <p className="mt-3 text-sm text-slate-600">
                    Sistem untuk {tenantName ?? "klinik ini"} sedang dibuat.
                    Proses ini biasanya selesai dalam beberapa menit. Muat ulang
                    halaman sebentar lagi.
                </p>
            </div>
        </div>
    );
}
