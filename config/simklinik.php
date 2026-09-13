<?php

declare(strict_types=1);

return [
    /*
    | Auto-logout setelah idle (FR-M21.6), dalam menit. Nilai bawaan untuk
    | semua klinik; tiap klinik bisa menimpanya lewat tenant_settings dengan
    | kunci `session.idle_timeout_minutes`.
    */
    'idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT', 15),

    /*
    | Batas bawah dan atas yang boleh dipilih klinik. Batas atas mencegah
    | klinik mematikan fitur ini dengan angka raksasa; komputer di meja
    | pendaftaran sering ditinggal dalam keadaan login.
    */
    'idle_timeout_bounds' => [5, 120],
];
