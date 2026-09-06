import { Head, usePage } from "@inertiajs/react";
import AppShell from "@/Layouts/AppShell";
import StatusBadge from "@/Components/StatusBadge";
import type { SharedProps } from "@/types";

export default function Dashboard({
    jumlahPengguna,
}: {
    jumlahPengguna: number;
}) {
    // Identitas klinik datang dari props bersama, bukan dari props halaman —
    // supaya setiap halaman tenant berikutnya tidak perlu mengirimnya ulang.
    const { tenant } = usePage<SharedProps>().props;

    if (!tenant) return null;

    return (
        <AppShell
            title={tenant.name}
            subtitle="Aplikasi klinik. Modul pendaftaran, antrian, dan encounter menyusul di Sprint 2."
        >
            <Head title={tenant.name} />

            <dl className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Status langganan
                    </dt>
                    <dd className="mt-2">
                        <StatusBadge
                            status={tenant.status}
                            label={tenant.statusLabel}
                        />
                    </dd>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Pengguna di database klinik ini
                    </dt>
                    <dd className="mt-2 text-2xl font-semibold tabular-nums">
                        {jumlahPengguna}
                    </dd>
                </div>
            </dl>

            <p className="mt-6 text-xs text-slate-500">
                Subdomain <code className="font-mono">{tenant.slug}</code>{" "}
                terhubung ke databasenya sendiri. Angka di atas dibaca dari
                database klinik ini, bukan dari database pusat.
            </p>
        </AppShell>
    );
}
