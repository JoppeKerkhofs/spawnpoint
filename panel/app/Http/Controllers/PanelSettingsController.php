<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
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
                'dns_target' => Setting::value('dns_target'),
            ],
            'hasCloudflareToken' => filled(Setting::value('cloudflare_token')),
            'dnsEnabled' => CloudflareDns::enabled(),
            // The key itself never goes to the browser.
            'hasCurseforgeKey' => filled(Setting::value('curseforge_key')),
            'effectiveMemoryLimitMb' => MemoryLimit::mb(),
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
            'curseforge_key' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_curseforge_key' => ['nullable', 'boolean'],
            'require_two_factor' => ['nullable', 'boolean'],
            'cloudflare_token' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_cloudflare_token' => ['nullable', 'boolean'],
            // An IP address, or a hostname for a CNAME record.
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
        ]);

        Setting::put('base_domain', $data['base_domain']);
        Setting::put('bluemap_url', $data['bluemap_url']);
        Setting::put('memory_limit_mb', isset($data['memory_limit_mb']) ? (string) $data['memory_limit_mb'] : null);
        Setting::put('require_two_factor', ($data['require_two_factor'] ?? false) ? '1' : null);

        // An empty key field means "keep the saved one".
        if ($data['remove_curseforge_key'] ?? false) {
            Setting::put('curseforge_key', null);
        } elseif (filled($data['curseforge_key'] ?? null)) {
            Setting::put('curseforge_key', $data['curseforge_key']);
        }

        Setting::put('dns_target', $data['dns_target'] ?? null);
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
