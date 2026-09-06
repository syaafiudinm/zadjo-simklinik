import { cn } from "@/Lib/utils";
import type { TenantStatus } from "@/types";

const styles: Record<TenantStatus, string> = {
    active: "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
    read_only: "bg-amber-50 text-amber-800 ring-amber-600/20",
    suspended: "bg-rose-50 text-rose-700 ring-rose-600/20",
    provisioning: "bg-slate-100 text-slate-600 ring-slate-500/20",
};

export default function StatusBadge({
    status,
    label,
    className,
}: {
    status: TenantStatus;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset",
                styles[status],
                className,
            )}
        >
            {label}
        </span>
    );
}
