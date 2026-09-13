import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import Button from "@/Components/ui/Button";
import { TextField } from "@/Components/ui/Field";
import GuestLayout from "@/Layouts/GuestLayout";

export default function ForgotPassword({ status }: { status?: string | null }) {
    const form = useForm({ email: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post("/forgot-password");
    };

    return (
        <GuestLayout title="Lupa password">
            <Head title="Lupa password" />

            <p className="mb-4 text-sm text-slate-600">
                Masukkan email akun Anda di klinik ini. Kami akan mengirim tautan untuk mengatur ulang password.
            </p>

            {status && (
                <p role="status" className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {status}
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData("email", e.target.value)}
                    error={form.errors.email}
                />
                <Button type="submit" className="w-full" disabled={form.processing}>
                    Kirim tautan
                </Button>
                <p className="text-center text-sm">
                    <Link href="/login" className="text-slate-600 underline-offset-4 hover:underline">
                        Kembali ke halaman masuk
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
