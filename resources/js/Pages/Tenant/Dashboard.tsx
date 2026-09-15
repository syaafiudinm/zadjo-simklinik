import { Head, usePage } from "@inertiajs/react";
import StatusBadge from "@/Components/StatusBadge";
import AppShell from "@/Layouts/AppShell";
import type { SharedProps } from "@/types";

export default function Dashboard({ jumlahPengguna }: { jumlahPengguna: number }) {
    // Identitas klinik datang dari props bersama, bukan dari props halaman —
    // supaya setiap halaman tenant berikutnya tidak perlu mengirimnya ulang.
    const { tenant, auth } = usePage<SharedProps>().props;

    if (!tenant) return null;

    return (
        <AppShell
            title={`Selamat datang, ${auth.user?.name ?? ""}`}
            subtitle="Modul pendaftaran, antrian, dan encounter menyusul di Sprint 2."
        >
            <Head title={tenant.name} />

            <dl className="grid gap-4 sm:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Status langganan</dt>
                    <dd className="mt-2">
                        <StatusBadge status={tenant.status} label={tenant.statusLabel} />
                    </dd>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Pengguna di klinik ini</dt>
                    <dd className="mt-2 text-2xl font-semibold tabular-nums">{jumlahPengguna}</dd>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Logout otomatis</dt>
                    <dd className="mt-2 text-sm text-slate-700">
                        Setelah <span className="font-semibold tabular-nums">{tenant.idleTimeoutMinutes}</span> menit tanpa aktivitas
                    </dd>
                </div>
            </dl>
        </AppShell>
    );
}
