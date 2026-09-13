export interface User {
    id: number;
    name: string;
    email: string;
}

export type TenantStatus =
    | "provisioning"
    | "active"
    | "read_only"
    | "suspended";

export interface TenantSummary {
    slug: string;
    name: string;
    status: TenantStatus;
    statusLabel: string;
    readOnly: boolean;
    idleTimeoutMinutes: number;
}

export interface RoleSummary {
    name: string;
    label: string;
}

/**
 * Props yang dibagikan ke setiap halaman lewat HandleInertiaRequests::share().
 *
 * Index signature-nya wajib: usePage<T>() dari Inertia mensyaratkan T bisa
 * diperlakukan sebagai Record<string, unknown>, karena props per-halaman ikut
 * bergabung ke objek yang sama.
 */
export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    auth: {
        user: User | null;
        /** Hanya untuk menyembunyikan menu. Server tetap memeriksa setiap aksi. */
        permissions: string[];
        roles: RoleSummary[];
    };
    /** null saat berada di konteks pusat, terisi saat di dalam subdomain klinik. */
    tenant: TenantSummary | null;
    flash: {
        status: string | null;
        success: string | null;
        error: string | null;
    };
}
