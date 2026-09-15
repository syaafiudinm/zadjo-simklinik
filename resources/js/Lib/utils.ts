import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

/** Menggabungkan className bersyarat dan menyelesaikan konflik utility Tailwind. */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
