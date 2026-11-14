<?php

return [

    // Every server hostname is one label under this domain, covered by a wildcard DNS record.
    'base_domain' => env('PANEL_BASE_DOMAIN', 'mc.localhost'),

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
