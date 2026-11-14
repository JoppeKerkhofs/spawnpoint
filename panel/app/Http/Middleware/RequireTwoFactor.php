<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    /**
     * Send users without two-factor authentication to set it up, when the panel requires it.
     * The account settings are outside this middleware, so they can always get there.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Setting::value('require_two_factor') && ! $request->user()->hasEnabledTwoFactorAuthentication()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This panel requires two-factor authentication. Set it up to continue.')]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
