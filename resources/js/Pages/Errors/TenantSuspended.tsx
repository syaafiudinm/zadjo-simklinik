import { Head } from "@inertiajs/react";

export default function TenantSuspended({
    tenantName,
}: {
    tenantName?: string;
}) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
            <Head title="Akses ditangguhkan" />
            <div className="max-w-md text-center">
                <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
                    Akses sedang ditangguhkan
                </h1>
                <p className="mt-3 text-sm text-slate-600">
                    Akses ke sistem {tenantName ?? "klinik ini"} sedang
                    dihentikan sementara. Rekam medis tidak dihapus dan tetap
                    dicadangkan.
                </p>
                <p className="mt-3 text-sm text-slate-600">
                    Hubungi administrator klinik atau tim dukungan SIMKlinik
                    untuk mengaktifkan kembali.
                </p>
            </div>
        </div>
    );
}
