import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import Button from "@/Components/ui/Button";
import { TextField } from "@/Components/ui/Field";
import GuestLayout from "@/Layouts/GuestLayout";

export default function Login({ status }: { status?: string | null }) {
    const form = useForm({ email: "", password: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post("/login", { onFinish: () => form.reset("password") });
    };

    return (
        <GuestLayout title="Masuk">
            <Head title="Masuk" />

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
                <TextField
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData("password", e.target.value)}
                    error={form.errors.password}
                />

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {form.processing ? "Memeriksa…" : "Masuk"}
                </Button>

                <p className="text-center text-sm">
                    <Link href="/forgot-password" className="text-slate-600 underline-offset-4 hover:underline">
                        Lupa password?
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
