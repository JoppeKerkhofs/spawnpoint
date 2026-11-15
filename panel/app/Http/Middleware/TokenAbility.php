<?php

namespace App\Http\Middleware;

use App\Api\Abilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the token may ask for, beside the can: middleware that says what its owner may do.
 * Used as ability:files:write on an API route; with several names, any one is enough.
 */
class TokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user() ?? abort(401);

        // Asked of the token one name at a time, never read from its list: a session answers yes
        // to everything, "*" does too, and a test token only knows how to answer.
        foreach ($abilities as $ability) {
            foreach (Abilities::grants($ability) as $name) {
                if ($user->tokenCan($name)) {
                    return $next($request);
                }
            }
        }

        abort(403, "This token lacks the {$abilities[0]} ability.");
    }
}
