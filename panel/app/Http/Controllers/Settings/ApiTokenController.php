<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    // What a token may do; it can never do more than its owner's role allows.
    public const array ABILITIES = ['servers:read', 'servers:power'];

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
            'abilities' => self::ABILITIES,
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
            'abilities.*' => [Rule::in(self::ABILITIES)],
        ]);

        $token = $request->user()->createToken($data['name'], $data['abilities']);

        return back()->with('new_token', $token->plainTextToken);
    }

    /**
     * Revoke a token.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back();
    }
}
