import { Head } from "@inertiajs/react";
import AppShell from "@/Layouts/AppShell";
import StatusBadge from "@/Components/StatusBadge";
import type { TenantStatus } from "@/types";

interface TenantRow {
    slug: string;
    name: string;
    status: TenantStatus;
    statusLabel: string;
    url: string;
}

export default function Home({ tenants }: { tenants: TenantRow[] }) {
    return (
        <AppShell
            title="SIMKlinik"
            subtitle="Domain pusat. Panel vendor menyusul di S1-10 — untuk sekarang halaman ini hanya memverifikasi bahwa registry tenant terbaca dari koneksi pusat."
        >
            <Head title="Beranda" />

            {tenants.length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                    Belum ada tenant. Jalankan{" "}
                    <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                        php artisan db:seed
                    </code>{" "}
                    untuk membuat tiga klinik contoh.
                </p>
            ) : (
                <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    {tenants.map((tenant) => (
                        <li
                            key={tenant.slug}
                            className="flex items-center justify-between gap-4 px-5 py-4"
                        >
                            <div className="min-w-0">
                                <a
                                    href={tenant.url}
                                    className="font-medium text-slate-900 underline-offset-4 hover:underline"
                                >
                                    {tenant.name}
                                </a>
                                <p className="truncate text-xs text-slate-500">
                                    {tenant.url}
                                </p>
                            </div>
                            <StatusBadge
                                status={tenant.status}
                                label={tenant.statusLabel}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
