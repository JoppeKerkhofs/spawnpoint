<?php

return [

    /*
    | The reverse proxies whose X-Forwarded-* headers are believed: `*` for
    | whichever proxy the request came through, or a comma-separated list of
    | addresses or ranges. Behind a TLS-terminating proxy this is what tells
    | the application that the visitor is on https and what their address is,
    | for the activity log and the login throttle. Empty means no proxy is trusted.
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
