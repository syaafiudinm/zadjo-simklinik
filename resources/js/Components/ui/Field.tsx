import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from "react";
import { useId } from "react";
import { cn } from "@/Lib/utils";

const control =
    "block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 aria-invalid:border-rose-500";

interface FieldShellProps {
    label: string;
    error?: string;
    hint?: ReactNode;
    children: (id: string, describedBy: string | undefined) => ReactNode;
}

function FieldShell({ label, error, hint, children }: FieldShellProps) {
    const id = useId();
    const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-slate-700">
                {label}
            </label>
            <div className="mt-1.5">{children(id, describedBy)}</div>
            {error ? (
                <p id={`${id}-error`} className="mt-1.5 text-sm text-rose-600">
                    {error}
                </p>
            ) : hint ? (
                <p id={`${id}-hint`} className="mt-1.5 text-xs text-slate-500">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

export function TextField({
    label,
    error,
    hint,
    className,
    ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: ReactNode }) {
    return (
        <FieldShell label={label} error={error} hint={hint}>
            {(id, describedBy) => (
                <input
                    id={id}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={cn(control, className)}
                    {...props}
                />
            )}
        </FieldShell>
    );
}

export function SelectField({
    label,
    error,
    hint,
    className,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string; hint?: ReactNode }) {
    return (
        <FieldShell label={label} error={error} hint={hint}>
            {(id, describedBy) => (
                <select
                    id={id}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={cn(control, className)}
                    {...props}
                >
                    {children}
                </select>
            )}
        </FieldShell>
    );
}
