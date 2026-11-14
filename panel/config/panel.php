<?php

return [

    // Every server hostname is one label under this domain, covered by a wildcard DNS record.
    'base_domain' => env('PANEL_BASE_DOMAIN', 'mc.localhost'),

    // Needed to search CurseForge and to install its packs. Never sent to the browser.
    'curseforge_key' => env('CURSEFORGE_API_KEY'),

];
