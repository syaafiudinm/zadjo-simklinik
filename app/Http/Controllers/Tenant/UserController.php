<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoleAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(fn (Role $role) => PermissionCatalog::roleLabel($role->name))->values(),
                'activated' => $user->activated_at !== null,
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('Users/Index', ['users' => $users]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Users/Create', [
            'roles' => $this->roleOptions($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $assignable = RoleAssignment::assignableBy($request->user())->pluck('name')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in($assignable)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'role.in' => 'Anda tidak dapat memberikan peran ini.',
            'email.unique' => 'Email ini sudah dipakai pengguna lain di klinik ini.',
        ]);

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            // Password ditetapkan admin, jadi akun langsung aktif. Ganti
            // password wajib saat login pertama menyusul bersama 2FA (FR-M21.5).
            $user->forceFill(['activated_at' => now()])->save();
            $user->assignRole($data['role']);
        });

        return redirect()->route('users.index')->with('success', "Pengguna {$data['name']} berhasil dibuat.");
    }

    /** @return list<array{value: string, label: string}> */
    private function roleOptions(User $actor): array
    {
        return RoleAssignment::assignableBy($actor)
            ->map(fn (Role $role) => ['value' => $role->name, 'label' => PermissionCatalog::roleLabel($role->name)])
            ->values()
            ->all();
    }
}
