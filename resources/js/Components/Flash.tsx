import { usePage } from "@inertiajs/react";
import type { SharedProps } from "@/types";

export default function Flash() {
    const { flash } = usePage<SharedProps>().props;

    const message = flash.error ?? flash.success ?? flash.status;
    if (!message) return null;

    const tone = flash.error
        ? "border-rose-200 bg-rose-50 text-rose-800"
        : "border-emerald-200 bg-emerald-50 text-emerald-800";

    return (
        <div role={flash.error ? "alert" : "status"} className={`rounded-md border px-4 py-3 text-sm ${tone}`}>
            {message}
        </div>
    );
}
