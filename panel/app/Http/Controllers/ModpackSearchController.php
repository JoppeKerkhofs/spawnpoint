<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Modpacks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModpackSearchController extends Controller
{
    /**
     * Search Modrinth or CurseForge for modpacks, for the picker in the server forms.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'platform' => ['required', Rule::in(Server::PACK_TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        if ($input['platform'] === 'AUTO_CURSEFORGE' && blank(config('panel.curseforge_key'))) {
            return response()->json(['message' => __('CurseForge needs CURSEFORGE_API_KEY in the panel environment.')], 422);
        }

        try {
            return response()->json(['packs' => Modpacks::search($input['platform'], (string) ($input['q'] ?? ''))]);
        } catch (ConnectionException|RequestException) {
            return response()->json(['message' => __('The modpack list could not be loaded.')], 502);
        }
    }
}
