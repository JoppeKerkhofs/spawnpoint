<?php

namespace App\Actions\Users;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser
{
    /**
     * Create a user. Without a password they are invited: an email lets them choose their own.
     *
     * @param  array<string, mixed>  $data
     * @return array{User, bool} the user and whether they were invited
     */
    public function __invoke(array $data): array
    {
        $invited = blank($data['password'] ?? null);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $invited ? Str::password(40) : $data['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();
        ActivityLog::record('user.create', properties: ['email' => $user->email, 'role' => $data['role'], 'invited' => $invited]);

        if ($invited) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return [$user, $invited];
    }
}
