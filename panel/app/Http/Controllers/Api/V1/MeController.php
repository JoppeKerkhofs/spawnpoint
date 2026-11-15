<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Abilities;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Languages;
use App\Services\MemoryLimit;
use App\Services\MinecraftVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /**
     * Who this token belongs to and what it may do. Needs no ability of its own: an app has to
     * be able to ask what it is allowed to show.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'locale' => $user->locale,
            // A session, and a token made for everything, both answer yes to "*".
            'abilities' => $user->tokenCan('*') ? ['*'] : Abilities::resolve(array_values($token->abilities ?: [])),
            'can' => [
                'manage_servers' => $user->can('manage-servers'),
                'manage_users' => $user->can('manage-users'),
                'search_modpacks' => $user->can('search-modpacks'),
            ],
        ]]);
    }

    /**
     * The choices the server forms offer, so an app can render them without reading a web page.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'base_domain' => Setting::value('base_domain'),
            'types' => Server::TYPES,
            'pack_types' => Server::PACK_TYPES,
            'java_versions' => Server::JAVA_VERSIONS,
            'mc_versions' => MinecraftVersions::releases(),
            'difficulties' => Server::DIFFICULTIES,
            'modes' => Server::MODES,
            'permissions' => Server::PERMISSIONS,
            'curseforge' => filled(Setting::value('curseforge_key')),
            'nodes' => Node::query()->orderBy('id')->get()->map(fn (Node $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'limit_mb' => MemoryLimit::mb($node),
                'free_mb' => MemoryLimit::freeMb($node),
            ]),
        ]]);
    }

    /**
     * Change the owner's display name and language. Never the e-mail, password, two-factor or tokens.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(Languages::available()))],
        ]);

        $request->user()->forceFill(array_filter([
            'name' => $data['name'],
            'locale' => $data['locale'] ?? null,
        ]))->save();

        return $this->show($request);
    }
}
