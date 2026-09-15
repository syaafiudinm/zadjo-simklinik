import { Head, Link } from "@inertiajs/react";
import AppShell from "@/Layouts/AppShell";
import { useCan } from "@/Lib/permissions";

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    activated: boolean;
    lastLoginAt: string | null;
}

const dateFormat = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" });

export default function UsersIndex({ users }: { users: UserRow[] }) {
    const can = useCan();

    return (
        <AppShell
            title="Pengguna"
            subtitle="Akun petugas di klinik ini beserta perannya."
            actions={
                can("user.create") && (
                    <Link
                        href="/users/create"
                        className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    >
                        Tambah pengguna
                    </Link>
                )
            }
        >
            <Head title="Pengguna" />

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="px-4 py-3">Nama</th>
                            <th scope="col" className="px-4 py-3">Peran</th>
                            <th scope="col" className="px-4 py-3">Status</th>
                            <th scope="col" className="px-4 py-3">Terakhir masuk</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {users.map((user) => (
                            <tr key={user.id}>
                                <td className="px-4 py-3">
                                    <div className="font-medium text-slate-900">{user.name}</div>
                                    <div className="text-xs text-slate-500">{user.email}</div>
                                </td>
                                <td className="px-4 py-3 text-slate-700">{user.roles.join(", ") || "—"}</td>
                                <td className="px-4 py-3">
                                    {user.activated ? (
                                        <span className="text-emerald-700">Aktif</span>
                                    ) : (
                                        <span className="text-amber-700">Menunggu undangan diterima</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 tabular-nums text-slate-600">
                                    {user.lastLoginAt ? dateFormat.format(new Date(user.lastLoginAt)) : "Belum pernah"}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppShell>
    );
}
