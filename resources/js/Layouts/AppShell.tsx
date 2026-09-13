import { Link, router, usePage } from "@inertiajs/react";
import type { PropsWithChildren, ReactNode } from "react";
import Flash from "@/Components/Flash";
import { useCan } from "@/Lib/permissions";
import { useIdleLogout } from "@/Lib/useIdleLogout";
import { cn } from "@/Lib/utils";
import type { SharedProps } from "@/types";

interface NavItem {
    label: string;
    href: string;
    /** Menu disembunyikan tanpa permission ini. Rutenya tetap dijaga di server. */
    permission?: string;
}

const NAV: NavItem[] = [
    { label: "Beranda", href: "/" },
    { label: "Pengguna", href: "/users", permission: "user.view" },
];

/**
 * Kerangka halaman untuk konteks pusat maupun tenant.
 *
 * Banner hanya-baca dipasang di sini, bukan di masing-masing halaman: satu
 * halaman yang lupa memasangnya akan membuat petugas mengira datanya tersimpan.
 */
export default function AppShell({
    title,
    subtitle,
    actions,
    children,
}: PropsWithChildren<{ title: string; subtitle?: ReactNode; actions?: ReactNode }>) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const can = useCan();
    const currentPath = typeof window !== "undefined" ? window.location.pathname : "/";

    useIdleLogout(tenant?.idleTimeoutMinutes, Boolean(tenant && auth.user));

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            {tenant?.readOnly && (
                <div className="bg-amber-100 px-4 py-2.5 text-center text-sm text-amber-900">
                    <strong className="font-semibold">Mode hanya-baca.</strong>{" "}
                    Rekam medis tetap dapat dibuka, tetapi perubahan baru tidak
                    dapat disimpan. Hubungi administrator klinik.
                </div>
            )}

            {tenant && auth.user && (
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-5xl items-center justify-between gap-6 px-6 py-3">
                        <div className="flex items-center gap-6">
                            <span className="text-sm font-semibold">{tenant.name}</span>
                            <nav className="flex gap-1">
                                {NAV.filter((item) => !item.permission || can(item.permission)).map((item) => {
                                    const active =
                                        item.href === "/" ? currentPath === "/" : currentPath.startsWith(item.href);

                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            className={cn(
                                                "rounded-md px-3 py-1.5 text-sm",
                                                active
                                                    ? "bg-slate-100 font-medium text-slate-900"
                                                    : "text-slate-600 hover:text-slate-900",
                                            )}
                                        >
                                            {item.label}
                                        </Link>
                                    );
                                })}
                            </nav>
                        </div>
                        <div className="flex items-center gap-3 text-sm">
                            <span className="text-right leading-tight">
                                <span className="block font-medium">{auth.user.name}</span>
                                <span className="block text-xs text-slate-500">
                                    {auth.roles.map((role) => role.label).join(", ") || "Tanpa peran"}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => router.post("/logout")}
                                className="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                            >
                                Keluar
                            </button>
                        </div>
                    </div>
                </header>
            )}

            <main className="mx-auto max-w-5xl px-6 py-10">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        {subtitle && <p className="mt-2 text-sm text-slate-600">{subtitle}</p>}
                    </div>
                    {actions}
                </div>
                <div className="mt-6 empty:hidden">
                    <Flash />
                </div>
                <div className="mt-8">{children}</div>
            </main>
        </div>
    );
}
