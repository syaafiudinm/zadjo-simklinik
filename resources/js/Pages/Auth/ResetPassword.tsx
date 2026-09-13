import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import Button from "@/Components/ui/Button";
import { TextField } from "@/Components/ui/Field";
import GuestLayout from "@/Layouts/GuestLayout";

interface Props {
    /** `invitation` untuk aktivasi akun baru, `reset` untuk lupa password. */
    mode: "reset" | "invitation";
    token: string;
    email: string;
}

export default function ResetPassword({ mode, token, email }: Props) {
    const form = useForm({ token, email, password: "", password_confirmation: "" });
    const invitation = mode === "invitation";

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(invitation ? "/invitation" : "/reset-password", {
            onFinish: () => form.reset("password", "password_confirmation"),
        });
    };

    const title = invitation ? "Aktifkan akun" : "Atur ulang password";

    return (
        <GuestLayout title={title}>
            <Head title={title} />

            {invitation && (
                <p className="mb-4 text-sm text-slate-600">
                    Tetapkan password untuk akun Anda. Setelah itu Anda langsung masuk ke sistem.
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <TextField label="Email" type="email" value={form.data.email} readOnly error={form.errors.email} />
                <TextField
                    label="Password baru"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData("password", e.target.value)}
                    error={form.errors.password}
                    hint="Minimal 10 karakter, berisi huruf dan angka."
                />
                <TextField
                    label="Ulangi password"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData("password_confirmation", e.target.value)}
                />
                <Button type="submit" className="w-full" disabled={form.processing}>
                    {invitation ? "Aktifkan & masuk" : "Simpan password"}
                </Button>
            </form>
        </GuestLayout>
    );
}
