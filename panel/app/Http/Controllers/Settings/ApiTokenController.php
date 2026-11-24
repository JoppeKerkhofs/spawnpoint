<?php

namespace App\Http\Controllers\Settings;

use App\Api\Abilities;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DevicePairing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * Show the user's API tokens.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => $request->user()->tokens()->latest()->get()->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used' => $token->last_used_at?->diffForHumans(),
                'created' => $token->created_at->diffForHumans(),
            ]),
            // Only the hash is stored, so this is the one time the token can be shown.
            'newToken' => $request->session()->get('new_token'),
        ]);
    }

    /**
     * The page a token is made on: by hand, or by pairing a device.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('settings/ApiTokenCreate', [
            // Only what this user could ever use: a viewer is not shown the administration group.
            ...Abilities::offeredTo($request->user()),
            // The device a pairing code was just looked up for, until it is approved.
            'pairing' => $request->session()->get('pairing'),
        ]);
    }

    /**
     * Create a token and show it once, on the list.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(Abilities::all())],
        ]);

        $token = $request->user()->createToken($data['name'], $data['abilities']);
        ActivityLog::record('token.create', properties: ['name' => $data['name'], 'abilities' => $data['abilities']]);

        return to_route('api-tokens.index')->with('new_token', $token->plainTextToken);
    }

    /**
     * Revoke a token.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        if ($request->user()->tokens()->whereKey($token)->delete()) {
            ActivityLog::record('token.revoke');
        }

        return back();
    }

    /**
     * Look a pairing code up, so the person sees which device is asking before they approve it.
     */
    public function pairing(Request $request): RedirectResponse
    {
        $pairing = $this->pendingPairing($request);

        return back()->with('pairing', $pairing->only('code', 'device_name'));
    }

    /**
     * Approve a device: mint a token with the chosen abilities and let the device collect it.
     */
    public function approve(Request $request): RedirectResponse
    {
        $pairing = $this->pendingPairing($request);

        // The looked-up device lives in the session for one request only. Kept for one more, so
        // a refused form comes back with the device and the picker instead of the empty code field.
        $request->session()->flash('pairing', $pairing->only('code', 'device_name'));

        $data = $request->validate([
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(Abilities::all())],
        ]);

        $pairing->approve($request->user(), array_values($data['abilities']));
        $request->session()->forget('pairing');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":device" is paired. It can sign in now.', ['device' => $pairing->device_name])]);

        return to_route('api-tokens.index');
    }

    /**
     * The pairing a code belongs to, if it is still waiting.
     */
    private function pendingPairing(Request $request): DevicePairing
    {
        $pairing = DevicePairing::waiting((string) $request->input('code'));

        if ($pairing === null) {
            throw ValidationException::withMessages(['code' => __('No device is waiting with that code. Codes expire after ten minutes.')]);
        }

        return $pairing;
    }
}
