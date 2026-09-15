<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Menerjemahkan event autentikasi dan otorisasi menjadi jejak audit.
 *
 * Skrip demo sprint langkah 7: "siapa login, siapa membuat user, kapan".
 */
class AuditSecurityEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [$this, 'login']);
        $events->listen(Logout::class, [$this, 'logout']);
        $events->listen(Failed::class, [$this, 'failed']);
        $events->listen(Lockout::class, [$this, 'lockout']);
        $events->listen(PasswordReset::class, [$this, 'passwordReset']);
        $events->listen(RoleAttachedEvent::class, [$this, 'roleAttached']);
        $events->listen(RoleDetachedEvent::class, [$this, 'roleDetached']);
    }

    public function login(Login $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(AuditEvent::Login, $event->user, actor: $event->user));
    }

    public function logout(Logout $event): void
    {
        $this->whenInTenant(fn () => $event->user && $this->audit->record(
            AuditEvent::Logout,
            $event->user,
            context: ['reason' => request()->attributes->get('logout_reason', 'manual')],
            actor: $event->user,
        ));
    }

    public function failed(Failed $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(
            AuditEvent::LoginFailed,
            $event->user instanceof User ? $event->user : null,
            // Email yang dicoba, bukan password. Email tak terdaftar pun
            // dicatat — itulah pola yang terlihat saat seseorang menebak akun.
            context: ['email' => $event->credentials['email'] ?? null],
        ));
    }

    public function lockout(Lockout $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(
            AuditEvent::Lockout,
            context: ['email' => $event->request->input('email')],
        ));
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(AuditEvent::PasswordReset, $event->user, actor: $event->user));
    }

    public function roleAttached(RoleAttachedEvent $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(
            AuditEvent::RoleAssigned,
            $event->model,
            new: ['roles' => $this->roleNames($event->rolesOrIds)],
        ));
    }

    public function roleDetached(RoleDetachedEvent $event): void
    {
        $this->whenInTenant(fn () => $this->audit->record(
            AuditEvent::RoleRevoked,
            $event->model,
            old: ['roles' => $this->roleNames($event->rolesOrIds)],
        ));
    }

    /**
     * Autentikasi dan role hanya ada di dalam tenant. Event yang sama di
     * konteks pusat (kelak: login vendor, S1-10) punya jejak auditnya sendiri.
     */
    private function whenInTenant(callable $record): void
    {
        if (tenancy()->initialized) {
            $record();
        }
    }

    /** @return list<string> */
    private function roleNames(mixed $rolesOrIds): array
    {
        return collect(is_iterable($rolesOrIds) ? $rolesOrIds : [$rolesOrIds])
            ->map(fn ($role) => $role instanceof Role ? $role->name : RoleModel::find($role)?->name ?? (string) $role)
            ->values()
            ->all();
    }
}
