<?php

return [

    // Every server hostname is one label under this domain, covered by a wildcard DNS record.
    'base_domain' => env('PANEL_BASE_DOMAIN', 'mc.localhost'),

    // Where the map proxy from compose.yaml is reachable; each server's map is at /<server>/ under it.
    'bluemap_url' => env('PANEL_BLUEMAP_URL', 'http://localhost:8100'),

    // Needed to search CurseForge and to install its packs. Never sent to the browser.
    'curseforge_key' => env('CURSEFORGE_API_KEY'),

];
