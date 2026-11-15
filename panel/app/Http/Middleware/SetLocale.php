<?php

namespace App\Http\Middleware;

use App\Services\Languages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Show the panel in the language the user chose, when there is a translation for it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if ($locale !== null && array_key_exists($locale, Languages::available())) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
