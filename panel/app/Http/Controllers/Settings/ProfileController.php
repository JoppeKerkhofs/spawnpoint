<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\MinecraftAccount;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email']));
        $this->linkMinecraft($user, $request->validated('minecraft_name'));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Link the Minecraft account with this name, or unlink when it is empty. The link is a claim,
     * not proof of ownership: it only decides which face is shown and which player counts as "you".
     */
    private function linkMinecraft(User $user, ?string $name): void
    {
        if (blank($name)) {
            $user->forceFill(['minecraft_name' => null, 'minecraft_uuid' => null]);

            return;
        }

        if (strcasecmp($name, (string) $user->minecraft_name) === 0) {
            return;
        }

        try {
            $account = MinecraftAccount::find($name);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            throw ValidationException::withMessages(['minecraft_name' => __('Mojang could not be asked about this name right now. Try again later.')]);
        }

        if ($account === null) {
            throw ValidationException::withMessages(['minecraft_name' => __('No Minecraft Java account has this name.')]);
        }

        $user->forceFill(['minecraft_name' => $account['name'], 'minecraft_uuid' => $account['id']]);
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
