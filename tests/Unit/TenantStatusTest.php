<?php

declare(strict_types=1);

use App\Enums\TenantStatus;

it('mengizinkan baca tapi tidak tulis pada status hanya-baca', function () {
    // FR-M23.4 — suspensi karena tunggakan tidak boleh menutup akses rekam
    // medis. Aturan ini hidup di enum, bukan tersebar di middleware, supaya
    // ada satu tempat yang bisa dibaca saat audit.
    expect(TenantStatus::ReadOnly->allowsAccess())->toBeTrue()
        ->and(TenantStatus::ReadOnly->allowsWrites())->toBeFalse();
});

it('menutup akses penuh hanya pada status suspended dan provisioning', function () {
    expect(TenantStatus::Suspended->allowsAccess())->toBeFalse()
        ->and(TenantStatus::Provisioning->allowsAccess())->toBeFalse()
        ->and(TenantStatus::Active->allowsAccess())->toBeTrue();
});

it('hanya mengizinkan tulis pada status aktif', function () {
    $menulis = array_values(array_filter(
        TenantStatus::cases(),
        fn (TenantStatus $status) => $status->allowsWrites()
    ));

    expect($menulis)->toBe([TenantStatus::Active]);
});

it('punya label berbahasa Indonesia untuk setiap status', function () {
    foreach (TenantStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }
});
