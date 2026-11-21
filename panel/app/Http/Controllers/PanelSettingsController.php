<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Setting;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use App\Services\PortalReleases;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PanelSettingsController extends Controller
{
    // What each section of the page saves.
    private const array SECTIONS = [
        'general' => ['base_domain', 'bluemap_url', 'memory_limit_mb'],
        'data' => ['activity_retention_days'],
        'services' => ['curseforge_key', 'remove_curseforge_key', 'cloudflare_token', 'remove_cloudflare_token', 'dns_target'],
        'security' => ['privacy_contact', 'require_two_factor'],
    ];

    /**
     * Show the panel-wide settings.
     */
    public function edit(PortalReleases $releases): Response
    {
        return Inertia::render('PanelSettings', [
            'launcherReleases' => $releases->listing(),
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
            // After the page: every node is asked, and one that is down takes seconds to say nothing.
            'backupFolders' => Inertia::defer(fn () => Node::all()->map(function (Node $node): array {
                try {
                    $folder = (new NodeClient($node))->backupFolder();
                } catch (ConnectionException|RequestException) {
                    $folder = null;
                }

                return ['node' => $node->name, 'folder' => $folder];
            })->all()),
        ]);
    }

    /**
     * Save the panel-wide settings.
     */
    public function update(Request $request): RedirectResponse
    {
        // A section of the page saves only its own fields; without one, everything is saved.
        $section = $request->validate(['section' => ['nullable', Rule::in(array_keys(self::SECTIONS))]])['section'] ?? null;
        $fields = $section === null ? array_merge(...array_values(self::SECTIONS)) : self::SECTIONS[$section];
        $saves = fn (string $field): bool => in_array($field, $fields, true);

        $data = $request->validate(Arr::only([
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
        ], $fields));

        if ($saves('base_domain')) {
            Setting::put('base_domain', $data['base_domain']);
            Setting::put('bluemap_url', $data['bluemap_url']);
            Setting::put('memory_limit_mb', isset($data['memory_limit_mb']) ? (string) $data['memory_limit_mb'] : null);
        }

        if ($saves('activity_retention_days')) {
            Setting::put('activity_retention_days', isset($data['activity_retention_days']) ? (string) $data['activity_retention_days'] : null);
        }

        if ($saves('curseforge_key')) {
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
        }

        if ($saves('privacy_contact')) {
            Setting::put('require_two_factor', ($data['require_two_factor'] ?? false) ? '1' : null);
            Setting::put('privacy_contact', $data['privacy_contact'] ?? null);
        }

        ActivityLog::record('settings.update');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
