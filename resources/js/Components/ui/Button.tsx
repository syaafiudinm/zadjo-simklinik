import type { ButtonHTMLAttributes } from "react";
import { cn } from "@/Lib/utils";

type Variant = "primary" | "secondary" | "ghost";

const variants: Record<Variant, string> = {
    primary: "bg-slate-900 text-white hover:bg-slate-800 disabled:bg-slate-400",
    secondary: "border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 disabled:text-slate-400",
    ghost: "text-slate-600 hover:bg-slate-100 hover:text-slate-900",
};

export default function Button({
    variant = "primary",
    className,
    type = "button",
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant }) {
    return (
        <button
            type={type}
            className={cn(
                "inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed",
                variants[variant],
                className,
            )}
            {...props}
        />
    );
}
