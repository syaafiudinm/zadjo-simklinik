import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import Button from "@/Components/ui/Button";
import { SelectField, TextField } from "@/Components/ui/Field";
import AppShell from "@/Layouts/AppShell";

interface Props {
    /** Hanya peran yang boleh diberikan oleh pengguna yang sedang login. */
    roles: { value: string; label: string }[];
}

export default function UsersCreate({ roles }: Props) {
    const form = useForm({ name: "", email: "", role: "", password: "", password_confirmation: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post("/users", { onError: () => form.reset("password", "password_confirmation") });
    };

    return (
        <AppShell title="Tambah pengguna">
            <Head title="Tambah pengguna" />

            <form onSubmit={submit} className="max-w-lg space-y-5 rounded-lg border border-slate-200 bg-white p-6">
                <TextField
                    label="Nama lengkap"
                    required
                    autoFocus
                    value={form.data.name}
                    onChange={(e) => form.setData("name", e.target.value)}
                    error={form.errors.name}
                />
                <TextField
                    label="Email"
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData("email", e.target.value)}
                    error={form.errors.email}
                />
                <SelectField
                    label="Peran"
                    required
                    value={form.data.role}
                    onChange={(e) => form.setData("role", e.target.value)}
                    error={form.errors.role}
                    hint="Peran menentukan menu dan data yang dapat dibuka pengguna ini."
                >
                    <option value="" disabled>
                        Pilih peran…
                    </option>
                    {roles.map((role) => (
                        <option key={role.value} value={role.value}>
                            {role.label}
                        </option>
                    ))}
                </SelectField>
                <TextField
                    label="Password awal"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData("password", e.target.value)}
                    error={form.errors.password}
                    hint="Minimal 10 karakter, berisi huruf dan angka. Sampaikan langsung ke pengguna, jangan lewat grup chat."
                />
                <TextField
                    label="Ulangi password"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData("password_confirmation", e.target.value)}
                />

                <div className="flex items-center justify-end gap-3 pt-2">
                    <Link href="/users" className="text-sm text-slate-600 hover:text-slate-900">
                        Batal
                    </Link>
                    <Button type="submit" disabled={form.processing}>
                        Simpan pengguna
                    </Button>
                </div>
            </form>
        </AppShell>
    );
}
