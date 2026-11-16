<?php

// One WebSocket server with one app, whose credentials are the ones in config/broadcasting.php.
$derive = fn (string $label, int $length): string => substr(hash_hmac('sha256', $label, (string) env('APP_KEY')), 0, $length);

return [

    'default' => 'reverb',

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8081),
            'path' => '',
            'hostname' => env('REVERB_HOST', 'realtime'),
            'options' => ['tls' => []],
            'max_request_size' => 10_000,
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                'key' => $derive('reverb-key', 32),
                'secret' => $derive('reverb-secret', 32),
                'app_id' => $derive('reverb-id', 20),
                'options' => ['host' => env('REVERB_HOST', 'realtime'), 'port' => env('REVERB_PORT', 8081), 'scheme' => 'http', 'useTLS' => false],
                // Joining a channel needs the panel's signature, so the origin adds nothing.
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
                // Pages only listen.
                'accept_client_events_from' => 'none',
            ],
        ],
    ],

];
