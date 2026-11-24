<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Getting a token onto a device without a password ever reaching it: the app shows a code, a
 * person approves it in the panel, and the app collects the token that approval minted.
 */
class PairingController extends Controller
{
    /**
     * Start a pairing. The answer holds the code to show and the secret to keep. An app that
     * names what it needs also gets the page to open, where a signed-in person allows it.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_name' => ['required', 'string', 'max:60'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => [Rule::in(DevicePairing::REQUESTABLE)],
        ]);
        $requested = isset($data['abilities']) ? array_values(array_unique($data['abilities'])) : null;

        // Nobody will ever collect these; this is the cheapest moment to clear them out.
        DevicePairing::pruneExpired();

        $secret = DevicePairing::secret();
        $pairing = DevicePairing::create([
            'code' => DevicePairing::newCode(),
            'secret_hash' => hash('sha256', $secret),
            'device_name' => $data['device_name'],
            'requested_abilities' => $requested,
            'expires_at' => now()->addMinutes(DevicePairing::MINUTES),
            'created_at' => now(),
        ]);

        return response()->json(['data' => [
            'code' => $pairing->code,
            'secret' => $secret,
            'device_name' => $pairing->device_name,
            'expires_at' => $pairing->expires_at->toIso8601String(),
            // Seconds between polls.
            'interval' => 3,
            // A path, not an address: the app knows which address it reached this panel at.
            ...($requested === null ? [] : ['approve_path' => "/pair/{$pairing->code}"]),
        ]], 201);
    }

    /**
     * Collect the token once it has been approved. The pairing is gone afterwards.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:8'],
            'secret' => ['required', 'string', 'size:64'],
        ]);

        $pairing = DevicePairing::query()->where('code', $data['code'])->where('expires_at', '>', now())->first();

        // One answer for unknown, expired and wrong: a guesser learns nothing from it.
        abort_if($pairing === null || ! $pairing->matches($data['secret']), 404, 'No such pairing.');

        if ($pairing->approved_at === null) {
            return response()->json(['status' => 'pending'], 202);
        }

        $token = $pairing->token;
        $abilities = $pairing->abilities ?? [];
        $user = $pairing->user()->first();
        $pairing->delete();

        return response()->json([
            'token' => $token,
            'abilities' => $abilities,
            'user' => ['id' => $user?->id, 'name' => $user?->name, 'role' => $user?->role],
        ]);
    }
}
