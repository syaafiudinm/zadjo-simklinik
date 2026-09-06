import { Head } from "@inertiajs/react";

export default function TenantNotFound({ host }: { host?: string }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
            <Head title="Klinik tidak ditemukan" />
            <div className="max-w-md text-center">
                <p className="text-sm font-semibold text-slate-400">404</p>
                <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">
                    Klinik tidak ditemukan
                </h1>
                <p className="mt-3 text-sm text-slate-600">
                    Tidak ada klinik yang terdaftar di alamat{" "}
                    {host ? (
                        <code className="font-mono text-slate-800">{host}</code>
                    ) : (
                        "ini"
                    )}
                    . Periksa kembali ejaan alamatnya, atau hubungi
                    administrator klinik Anda.
                </p>
            </div>
        </div>
    );
}
