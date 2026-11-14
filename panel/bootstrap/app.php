<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // File contents from the editor must be saved exactly as typed, trailing newline included.
        $middleware->trimStrings(except: ['content']);

        $middleware->web(append: [
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
        $exceptions->render(fn (RequestException $exception, Request $request) => $nodeFailure($exception, $request));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
