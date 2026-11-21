<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUser;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List the users with their role and the servers shared with them.
     */
    public function index(): Response
    {
        return Inertia::render('users/Index', [
            'users' => User::query()->orderBy('name')->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'manages_launcher' => $user->manages_launcher,
                // Server id to the permissions of that share; an empty list is "may see it".
                'shares' => (object) DB::table('server_user')->where('user_id', $user->id)->pluck('permissions', 'server_id')
                    ->map(fn (?string $permissions): array => json_decode((string) $permissions, true) ?? [])->all(),
            ]),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'permissions' => Server::PERMISSIONS,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Create a user. There is no public sign-up, so this is the only way in.
     */
    public function store(Request $request, CreateUser $createUser): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', Password::defaults()],
            ...$this->accessRules(),
        ]);

        [$user, $invited] = $createUser($data);
        $this->grant($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => $invited
            ? __('User created. They get an email with a link to set their password.')
            : __('User created. Give them the password; they can change it after logging in.')]);

        return back();
    }

    /**
     * Change a user's role and the servers shared with them.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->accessRules());

        // Keeps at least one admin: the one doing the editing.
        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            throw ValidationException::withMessages(['role' => __('You cannot remove your own admin role.')]);
        }

        $this->grant($user, $data);
        ActivityLog::record('user.update', properties: ['email' => $user->email, 'role' => $data['role']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /**
     * Delete a user; servers they created pass to the admin deleting them.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => __('You cannot delete your own account here.')]);
        }

        Server::query()->where('owner_id', $user->id)->update(['owner_id' => $request->user()->id]);
        $user->delete();
        ActivityLog::record('user.delete', properties: ['email' => $user->email]);

        return back();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accessRules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'viewer'])],
            // A viewer who may also manage the launcher's releases; an admin always may.
            'manages_launcher' => ['nullable', 'boolean'],
            // The servers shared with a viewer, and per server what they may do there.
            'shared' => ['nullable', 'array'],
            'shared.*' => ['integer', 'exists:servers,id'],
            'permissions' => ['nullable', 'array'],
            // One entry per ticked box, as "server id:permission".
            'permissions.*' => ['string', 'regex:/^\d+:('.implode('|', Server::PERMISSIONS).')$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function grant(User $user, array $data): void
    {
        // The role is not mass assignable, so a profile form can never change it.
        $user->forceFill(['role' => $data['role'], 'manages_launcher' => $data['role'] === 'viewer' && ($data['manages_launcher'] ?? false)])->save();
        // Admins see every server, so sharing only means something for viewers.
        $shares = [];
        foreach ($data['role'] === 'viewer' ? ($data['shared'] ?? []) : [] as $serverId) {
            $shares[(int) $serverId] = [];
        }
        foreach ($data['permissions'] ?? [] as $entry) {
            [$serverId, $permission] = explode(':', $entry);
            if (isset($shares[(int) $serverId])) {
                $shares[(int) $serverId][] = $permission;
            }
        }
        $user->servers()->sync(array_map(fn (array $permissions): array => ['permissions' => json_encode($permissions)], $shares));
    }
}
