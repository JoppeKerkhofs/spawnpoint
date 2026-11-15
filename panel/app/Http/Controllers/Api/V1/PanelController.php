<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Setting;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /**
     * The panel-wide settings. A secret is reported as a flag, never as its value.
     */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            'base_domain' => Setting::value('base_domain'),
            'bluemap_url' => Setting::value('bluemap_url'),
            'memory_limit_mb' => Setting::value('memory_limit_mb'),
            'require_two_factor' => (bool) Setting::value('require_two_factor'),
            'activity_retention_days' => Setting::value('activity_retention_days'),
            'dns_target' => Setting::value('dns_target'),
            'privacy_contact' => Setting::value('privacy_contact'),
            'has_curseforge_key' => filled(Setting::value('curseforge_key')),
            'has_cloudflare_token' => filled(Setting::value('cloudflare_token')),
            'dns_enabled' => CloudflareDns::enabled(),
            'effective_memory_limit_mb' => MemoryLimit::mb(Node::query()->first()),
        ]]);
    }

    /**
     * Change the panel-wide settings. An empty secret keeps the saved one.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/'],
            'bluemap_url' => ['required', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
            'activity_retention_days' => ['nullable', 'integer', 'between:0,3650'],
            'require_two_factor' => ['nullable', 'boolean'],
            'privacy_contact' => ['nullable', 'string', 'max:200'],
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
            'curseforge_key' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'cloudflare_token' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_curseforge_key' => ['nullable', 'boolean'],
            'remove_cloudflare_token' => ['nullable', 'boolean'],
        ]);

        Setting::put('base_domain', $data['base_domain']);
        Setting::put('bluemap_url', $data['bluemap_url']);
        Setting::put('memory_limit_mb', isset($data['memory_limit_mb']) ? (string) $data['memory_limit_mb'] : null);
        Setting::put('activity_retention_days', isset($data['activity_retention_days']) ? (string) $data['activity_retention_days'] : null);
        Setting::put('require_two_factor', ($data['require_two_factor'] ?? false) ? '1' : null);
        Setting::put('dns_target', $data['dns_target'] ?? null);
        Setting::put('privacy_contact', $data['privacy_contact'] ?? null);

        // An empty secret means "keep the saved one"; removing it is asked for by name.
        foreach (['curseforge_key', 'cloudflare_token'] as $secret) {
            if ($data["remove_{$secret}"] ?? false) {
                Setting::put($secret, null);
            } elseif (filled($data[$secret] ?? null)) {
                Setting::put($secret, $data[$secret]);
            }
        }

        ActivityLog::record('settings.update');

        return $this->settings();
    }

    /**
     * Who did what, newest first, fifty at a time.
     */
    public function activity(Request $request): JsonResponse
    {
        $server = $request->string('server')->toString();
        $page = ActivityLog::query()
            ->when($server !== '', fn ($query) => $query->where('subject', $server))
            ->latest('id')
            ->simplePaginate(50)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id,
                'actor' => $entry->actor,
                'event' => $entry->event,
                'subject' => $entry->subject,
                'properties' => $entry->properties,
                'at' => $entry->created_at->toIso8601String(),
            ]),
            'older_url' => $page->nextPageUrl(),
            'newer_url' => $page->previousPageUrl(),
        ]);
    }
}
