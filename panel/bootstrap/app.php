<?php

use App\Exceptions\ModrinthUnavailable;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PasskeysFollowTheAddress;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TokenAbility;
use App\Services\Languages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Which proxies are trusted is set in config/trustedproxy.php. From them only the scheme,
        // port and client address are taken; the host comes from the Host header.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // File contents from the editor must be saved exactly as typed, trailing newline included.
        $middleware->trimStrings(except: ['content']);

        // Guards an API route by what the token may do; the can: middleware guards what its owner may do.
        $middleware->alias(['ability' => TokenAbility::class]);

        $middleware->web(append: [
            PasskeysFollowTheAddress::class,
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An API client gets a plain answer when the Rust service is down or refuses, not a stack trace.
        $nodeFailure = fn (Throwable $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'The Rust service did not answer or refused the request.'], 502)
            : null;
        $exceptions->render(fn (ConnectionException $exception, Request $request) => $nodeFailure($exception, $request));
        $exceptions->render(fn (RequestException $exception, Request $request) => $request->is('api/*') && in_array($exception->response->status(), [403, 404, 409, 413, 415], true)
            ? response()->json(['message' => 'The Rust service refused: '.$exception->response->status().'.'], $exception->response->status())
            : $nodeFailure($exception, $request));

        $exceptions->render(fn (ModrinthUnavailable $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Modrinth could not be reached.'], 502)
            : null);

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('The page had expired. Please try again.')]);

                return back();
            }

            // With debugging on, a crash keeps Laravel's page with the stack trace.
            if (in_array($status, [403, 404, 500, 503], true) && ! ($status === 500 && config('app.debug'))) {
                return Inertia::render('Error', [
                    'status' => $status,
                    // Given here too: an address that matches no route skips the middleware that shares them.
                    'theme' => config('panel.theme'),
                    'name' => config('app.name'),
                    'translations' => (object) Languages::translations(app()->getLocale()),
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
