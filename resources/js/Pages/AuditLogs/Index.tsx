import { Head, Link, router } from "@inertiajs/react";
import { Fragment, useState, type FormEvent } from "react";
import Button from "@/Components/ui/Button";
import { SelectField, TextField } from "@/Components/ui/Field";
import AppShell from "@/Layouts/AppShell";
import { cn } from "@/Lib/utils";

type Values = Record<string, unknown> | null;

interface LogRow {
    id: number;
    occurredAt: string;
    event: string;
    eventLabel: string;
    securitySignal: boolean;
    actor: string | null;
    subjectType: string | null;
    subject: string | null;
    oldValues: Values;
    newValues: Values;
    reason: string | null;
    context: Values;
    channel: string;
    ipAddress: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
}

interface Filters {
    event: string | null;
    actor: number | null;
    from: string | null;
    to: string | null;
    q: string | null;
}

interface Props {
    logs: Paginated<LogRow>;
    filters: Filters;
    events: { value: string; label: string }[];
}

const timeFormat = new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "medium",
});

const CHANNEL_LABEL: Record<string, string> = {
    http: "Web",
    queue: "Antrian",
    console: "Sistem",
};

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return "—";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}

function Changes({ log }: { log: LogRow }) {
    const keys = Array.from(
        new Set([...Object.keys(log.oldValues ?? {}), ...Object.keys(log.newValues ?? {})]),
    );

    return (
        <div className="space-y-3 text-xs">
            {log.reason && (
                <p>
                    <span className="font-medium text-slate-700">Alasan: </span>
                    {log.reason}
                </p>
            )}
            {keys.length > 0 && (
                <table className="w-full table-fixed border-collapse">
                    <thead className="text-left text-slate-500">
                        <tr>
                            <th scope="col" className="w-1/4 py-1 pr-3 font-medium">Kolom</th>
                            <th scope="col" className="py-1 pr-3 font-medium">Sebelum</th>
                            <th scope="col" className="py-1 font-medium">Sesudah</th>
                        </tr>
                    </thead>
                    <tbody className="font-mono">
                        {keys.map((key) => (
                            <tr key={key} className="border-t border-slate-100 align-top">
                                <td className="py-1 pr-3 text-slate-600">{key}</td>
                                <td className="break-all py-1 pr-3 text-rose-700">{formatValue(log.oldValues?.[key])}</td>
                                <td className="break-all py-1 text-emerald-700">{formatValue(log.newValues?.[key])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {log.context && (
                <p className="break-all font-mono text-slate-500">{JSON.stringify(log.context)}</p>
            )}
            <p className="text-slate-500">
                Kanal: {CHANNEL_LABEL[log.channel] ?? log.channel}
                {log.ipAddress && ` · IP ${log.ipAddress}`}
            </p>
        </div>
    );
}

export default function AuditLogsIndex({ logs, filters, events }: Props) {
    const [form, setForm] = useState({
        event: filters.event ?? "",
        from: filters.from ?? "",
        to: filters.to ?? "",
        q: filters.q ?? "",
    });
    const [expanded, setExpanded] = useState<number | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ""));
        router.get("/audit-logs", query, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppShell
            title="Log audit"
            subtitle="Jejak siapa melakukan apa, kapan. Catatan tidak dapat diubah atau dihapus oleh siapa pun dari dalam aplikasi."
        >
            <Head title="Log audit" />

            <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-5 sm:items-end">
                <SelectField label="Kejadian" value={form.event} onChange={(e) => setForm({ ...form, event: e.target.value })}>
                    <option value="">Semua</option>
                    {events.map((event) => (
                        <option key={event.value} value={event.value}>
                            {event.label}
                        </option>
                    ))}
                </SelectField>
                <TextField label="Dari" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                <TextField label="Sampai" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                <TextField label="Cari pelaku / objek" value={form.q} onChange={(e) => setForm({ ...form, q: e.target.value })} />
                <Button type="submit" variant="secondary">
                    Terapkan
                </Button>
            </form>

            <p className="mt-4 text-xs text-slate-500">
                {logs.total === 0 ? "Tidak ada catatan." : `Menampilkan ${logs.from}–${logs.to} dari ${logs.total} catatan`}
            </p>

            <div className="mt-2 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="px-4 py-3">Waktu</th>
                            <th scope="col" className="px-4 py-3">Kejadian</th>
                            <th scope="col" className="px-4 py-3">Pelaku</th>
                            <th scope="col" className="px-4 py-3">Objek</th>
                            <th scope="col" className="px-4 py-3">
                                <span className="sr-only">Detail</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {logs.data.map((log) => (
                            <Fragment key={log.id}>
                                <tr className={cn(log.securitySignal && "bg-amber-50/60")}>
                                    <td className="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">
                                        {timeFormat.format(new Date(log.occurredAt))}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={cn("font-medium", log.securitySignal ? "text-amber-800" : "text-slate-900")}>
                                            {log.eventLabel}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-700">{log.actor ?? <span className="text-slate-400">Sistem</span>}</td>
                                    <td className="px-4 py-3 text-slate-700">
                                        {log.subject ? (
                                            <>
                                                <span className="text-xs text-slate-400">{log.subjectType} · </span>
                                                {log.subject}
                                            </>
                                        ) : (
                                            "—"
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            aria-expanded={expanded === log.id}
                                            onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                                            className="text-xs font-medium text-slate-600 hover:text-slate-900"
                                        >
                                            {expanded === log.id ? "Tutup" : "Detail"}
                                        </button>
                                    </td>
                                </tr>
                                {expanded === log.id && (
                                    <tr className="bg-slate-50">
                                        <td colSpan={5} className="px-4 py-3">
                                            <Changes log={log} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            {logs.links.length > 3 && (
                <nav className="mt-4 flex flex-wrap gap-1" aria-label="Halaman">
                    {logs.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                className={cn(
                                    "rounded-md px-3 py-1.5 text-sm",
                                    link.active ? "bg-slate-900 text-white" : "text-slate-600 hover:bg-slate-100",
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={index}
                                className="px-3 py-1.5 text-sm text-slate-300"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </nav>
            )}
        </AppShell>
    );
}
