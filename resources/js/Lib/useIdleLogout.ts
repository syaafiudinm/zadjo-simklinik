import { router } from "@inertiajs/react";
import { useEffect, useRef } from "react";

const ACTIVITY_EVENTS = ["mousemove", "mousedown", "keydown", "scroll", "touchstart"] as const;

/** Jeda minimum antar heartbeat saat pengguna aktif tanpa berpindah halaman. */
const HEARTBEAT_INTERVAL_MS = 60_000;

/**
 * Logout otomatis di sisi klien setelah idle (FR-M21.6).
 *
 * Server sudah menegakkan batas idle (EnforceIdleTimeout), tapi hanya pada
 * request berikutnya. Tanpa hook ini, layar berisi data pasien tetap terbuka
 * di meja yang ditinggal sampai seseorang menyentuhnya.
 *
 * Sebaliknya, petugas yang mengetik anamnesis panjang tanpa berpindah halaman
 * tidak boleh ter-logout saat menekan simpan. Selama ada aktivitas, heartbeat
 * dikirim paling sering sekali per menit untuk memperbarui cap waktu di server.
 */
export function useIdleLogout(minutes: number | undefined, enabled: boolean) {
    const lastActivity = useRef(Date.now());
    const lastHeartbeat = useRef(Date.now());

    useEffect(() => {
        if (!enabled || !minutes) return;

        const limitMs = minutes * 60_000;

        const onActivity = () => {
            lastActivity.current = Date.now();
        };

        ACTIVITY_EVENTS.forEach((name) =>
            window.addEventListener(name, onActivity, { passive: true }),
        );

        const timer = window.setInterval(() => {
            const now = Date.now();

            if (now - lastActivity.current >= limitMs) {
                window.clearInterval(timer);
                router.post("/logout", { reason: "idle" });
                return;
            }

            const activeSinceHeartbeat = lastActivity.current > lastHeartbeat.current;
            if (activeSinceHeartbeat && now - lastHeartbeat.current >= HEARTBEAT_INTERVAL_MS) {
                lastHeartbeat.current = now;
                fetch("/session/heartbeat", {
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                }).catch(() => {
                    // Jaringan putus: biarkan. Batas di server tetap berlaku.
                });
            }
        }, 15_000);

        return () => {
            window.clearInterval(timer);
            ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, onActivity));
        };
    }, [minutes, enabled]);
}
