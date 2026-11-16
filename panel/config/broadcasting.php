<?php

// The WebSocket server's credentials come from APP_KEY, so there is nothing more to set.
$derive = fn (string $label, int $length): string => substr(hash_hmac('sha256', $label, (string) env('APP_KEY')), 0, $length);

return [

    // "reverb" where the realtime container runs; "null" means pages poll.
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => $derive('reverb-key', 32),
            'secret' => $derive('reverb-secret', 32),
            'app_id' => $derive('reverb-id', 20),
            // Where the panel publishes: the realtime container, over the private network.
            'options' => [
                'host' => env('REVERB_HOST', 'realtime'),
                'port' => env('REVERB_PORT', 8081),
                'scheme' => 'http',
                'useTLS' => false,
            ],
            // An absent WebSocket server must not hold up the action that announces something.
            'client_options' => ['connect_timeout' => 1, 'timeout' => 2],
            // Where the browser connects when that is not the panel's own address (development).
            'browser' => ['host' => env('REVERB_BROWSER_HOST'), 'port' => env('REVERB_BROWSER_PORT')],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],

    ],

];
