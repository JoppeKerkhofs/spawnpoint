<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Setting;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PanelSettingsController extends Controller
{
    /**
     * Show the panel-wide settings.
     */
    public function edit(): Response
    {
        return Inertia::render('PanelSettings', [
            'settings' => [
                'base_domain' => Setting::value('base_domain'),
                'bluemap_url' => Setting::value('bluemap_url'),
                'memory_limit_mb' => Setting::value('memory_limit_mb'),
                'require_two_factor' => (bool) Setting::value('require_two_factor'),
                'activity_retention_days' => Setting::value('activity_retention_days'),
                'dns_target' => Setting::value('dns_target'),
                'privacy_contact' => Setting::value('privacy_contact'),
            ],
            'hasCloudflareToken' => filled(Setting::value('cloudflare_token')),
            'dnsEnabled' => CloudflareDns::enabled(),
            // The key itself never goes to the browser.
            'hasCurseforgeKey' => filled(Setting::value('curseforge_key')),
            // Of the first node: the others can each have their own limit, on the Nodes page.
            'effectiveMemoryLimitMb' => MemoryLimit::mb(Node::query()->first()),
        ]);
    }

    /**
     * Save the panel-wide settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/'],
            'bluemap_url' => ['required', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
            // Days of activity log to keep; 0 keeps everything.
            'activity_retention_days' => ['nullable', 'integer', 'between:0,3650'],
            'curseforge_key' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_curseforge_key' => ['nullable', 'boolean'],
            'require_two_factor' => ['nullable', 'boolean'],
            'cloudflare_token' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_cloudflare_token' => ['nullable', 'boolean'],
            // Shown on the public privacy policy as the one responsible for this installation.
            'privacy_contact' => ['nullable', 'string', 'max:200'],
            // An IP address, or a hostname for a CNAME record.
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
        ]);

        Setting::put('base_domain', $data['base_domain']);
        Setting::put('bluemap_url', $data['bluemap_url']);
        Setting::put('memory_limit_mb', isset($data['memory_limit_mb']) ? (string) $data['memory_limit_mb'] : null);
        Setting::put('require_two_factor', ($data['require_two_factor'] ?? false) ? '1' : null);
        Setting::put('activity_retention_days', isset($data['activity_retention_days']) ? (string) $data['activity_retention_days'] : null);

        // An empty key field means "keep the saved one".
        if ($data['remove_curseforge_key'] ?? false) {
            Setting::put('curseforge_key', null);
        } elseif (filled($data['curseforge_key'] ?? null)) {
            Setting::put('curseforge_key', $data['curseforge_key']);
        }

        Setting::put('dns_target', $data['dns_target'] ?? null);
        Setting::put('privacy_contact', $data['privacy_contact'] ?? null);
        if ($data['remove_cloudflare_token'] ?? false) {
            Setting::put('cloudflare_token', null);
        } elseif (filled($data['cloudflare_token'] ?? null)) {
            Setting::put('cloudflare_token', $data['cloudflare_token']);
        }

        ActivityLog::record('settings.update');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
