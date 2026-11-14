<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'users' => User::query()->with('servers:id')->orderBy('name')->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'servers' => $user->servers->pluck('id'),
            ]),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Create a user. There is no public sign-up, so this is the only way in.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            ...$this->accessRules(),
        ]);

        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $this->grant($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created. Give them the password; they can change it after logging in.')]);

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

        return back();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accessRules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'viewer'])],
            'servers' => ['nullable', 'array'],
            'servers.*' => ['integer', 'exists:servers,id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function grant(User $user, array $data): void
    {
        // The role is not mass assignable, so a profile form can never change it.
        $user->forceFill(['role' => $data['role']])->save();
        // Admins see every server, so sharing only means something for viewers.
        $user->servers()->sync($data['role'] === 'viewer' ? ($data['servers'] ?? []) : []);
    }
}
