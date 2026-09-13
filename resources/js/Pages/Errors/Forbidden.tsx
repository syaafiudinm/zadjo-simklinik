import { Head, Link, usePage } from "@inertiajs/react";
import type { SharedProps } from "@/types";

export default function Forbidden({ message }: { message: string }) {
    const { auth } = usePage<SharedProps>().props;

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
            <Head title="Akses ditolak" />
            <div className="max-w-md text-center">
                <p className="text-sm font-semibold text-slate-400">403</p>
                <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Akses ditolak</h1>
                <p className="mt-3 text-sm text-slate-600">{message}</p>
                {auth.user && (
                    <Link href="/" className="mt-6 inline-block text-sm font-medium text-slate-900 underline underline-offset-4">
                        Kembali ke beranda
                    </Link>
                )}
            </div>
        </div>
    );
}
