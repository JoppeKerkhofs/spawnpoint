<?php

// Defaults from the environment. An admin can override the first five on the panel's Settings page.

return [

    // Every server hostname is one label under this domain, covered by a wildcard DNS record.
    'base_domain' => env('PANEL_BASE_DOMAIN', 'mc.localhost'),

    // Who runs this installation, for the privacy policy: a name and a way to reach them.
    'privacy_contact' => env('PANEL_PRIVACY_CONTACT'),

    // The look of the panel: "default", or "blocky" for the Minecraft-style one.
    'theme' => in_array(env('PANEL_THEME'), ['blocky'], true) ? env('PANEL_THEME') : 'default',

    // With both filled in, a DNS record is added in Cloudflare for every new server and removed with it.
    'cloudflare_token' => env('CLOUDFLARE_API_TOKEN'),
    'dns_target' => env('PANEL_DNS_TARGET'),

    // Send every user without two-factor authentication to set it up before anything else.
    'require_two_factor' => env('PANEL_REQUIRE_TWO_FACTOR', false),

    // The most memory, in MB, all servers together may be given. Empty means 90% of the host's memory.
    'memory_limit_mb' => env('PANEL_MEMORY_LIMIT_MB'),

    // Where this panel reaches the Rust service that panel:setup registers.
    'node_url' => env('PANEL_NODE_URL', 'http://127.0.0.1:8080'),

    // Where the browser reaches it, for the console and file transfers. A path such as /node means
    // "through this panel's own web server"; empty means the node's own address.
    'node_public_url' => env('PANEL_NODE_PUBLIC_URL'),

    // Where the map proxy from compose.yaml is reachable; each server's map is at /<server>/ under it.
    'bluemap_url' => env('PANEL_BLUEMAP_URL', 'http://localhost:8100'),

    // Needed to search CurseForge and to install its packs. Never sent to the browser.
    'curseforge_key' => env('CURSEFORGE_API_KEY'),

];
