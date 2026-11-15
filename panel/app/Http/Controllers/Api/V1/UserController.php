<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * The users with their role and the servers shared with them.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('name')->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            // Server id to what that share allows; an empty list is "may see it".
            'shares' => (object) DB::table('server_user')->where('user_id', $user->id)->pluck('permissions', 'server_id')
                ->map(fn (?string $permissions): array => json_decode((string) $permissions, true) ?? [])->all(),
        ])]);
    }

    /**
     * Create a user. Without a password they are invited by e-mail.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', Password::defaults()],
            ...$this->accessRules(),
        ]);

        $invited = blank($data['password'] ?? null);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $invited ? Str::password(40) : $data['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->grant($user, $data);
        ActivityLog::record('user.create', properties: ['email' => $user->email, 'role' => $data['role'], 'invited' => $invited]);

        if ($invited) {
            PasswordBroker::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['data' => [...$user->only('id', 'name', 'email', 'role'), 'invited' => $invited]], 201);
    }

    /**
     * Change a user's role and what is shared with them.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate($this->accessRules());

        // Keeps at least one admin: the one doing the editing.
        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            return response()->json(['message' => __('You cannot remove your own admin role.')], 422);
        }

        $this->grant($user, $data);
        ActivityLog::record('user.update', properties: ['email' => $user->email, 'role' => $data['role']]);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'role')]);
    }

    /**
     * Delete a user; servers they created pass to the admin deleting them.
     */
    public function destroy(Request $request, User $user): JsonResponse|Response
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => __('You cannot delete your own account here.')], 422);
        }

        Server::query()->where('owner_id', $user->id)->update(['owner_id' => $request->user()->id]);
        $user->delete();
        ActivityLog::record('user.delete', properties: ['email' => $user->email]);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accessRules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'viewer'])],
            // Server id to what the share allows: {"3": ["power", "files"]}.
            'shares' => ['nullable', 'array'],
            'shares.*' => ['array'],
            'shares.*.*' => [Rule::in(Server::PERMISSIONS)],
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
        $shares = $data['role'] === 'viewer' ? ($data['shares'] ?? []) : [];
        $existing = Server::query()->whereKey(array_keys($shares))->pluck('id');

        $user->servers()->sync($existing->mapWithKeys(fn (int $id): array => [
            $id => ['permissions' => json_encode(array_values($shares[$id] ?? $shares[(string) $id] ?? []))],
        ])->all());
    }
}
