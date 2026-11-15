<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PasskeysFollowTheAddress
{
    /**
     * A passkey belongs to the address in the browser's bar. Going by the address of the request
     * instead of APP_URL keeps passkeys working when the panel is reached on another address
     * than the configured one, or when a proxy in front of it handles https.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'passkeys.relying_party_id' => $request->getHost(),
            'passkeys.allowed_origins' => array_values(array_unique([
                'https://'.$request->getHttpHost(),
                // Browsers only allow passkeys without https on localhost.
                'http://'.$request->getHttpHost(),
                rtrim((string) config('app.url'), '/'),
            ])),
        ]);

        return $next($request);
    }
}
