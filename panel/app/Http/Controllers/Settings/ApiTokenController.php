<?php

namespace App\Http\Controllers\Settings;

use App\Api\Abilities;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            // Only what this user could ever use: a viewer is not shown the administration group.
            ...Abilities::offeredTo($request->user()),
            // Only the hash is stored, so this is the one time the token can be shown.
            'newToken' => $request->session()->get('new_token'),
        ]);
    }

    /**
     * Create a token and show it once.
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

        return back()->with('new_token', $token->plainTextToken);
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
}
