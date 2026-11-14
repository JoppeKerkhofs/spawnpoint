<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

// What the owner may do on the server is checked by the route, the token's ability in the
// controller: a token never exceeds its owner.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('servers', [ServerController::class, 'index']);

    Route::middleware('can:view,server')->group(function () {
        Route::get('servers/{server}', [ServerController::class, 'show']);
        Route::get('servers/{server}/console', [ServerController::class, 'console']);
    });

    Route::get('servers/{server}/logs', [ServerController::class, 'logs'])->middleware('can:act,server,"logs"');

    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [ServerController::class, 'backups']);
        Route::post('servers/{server}/backups', [ServerController::class, 'backup']);
    });

    Route::middleware('can:act,server,"files"')->group(function () {
        Route::get('servers/{server}/files', [ServerController::class, 'files']);
        Route::put('servers/{server}/files', [ServerController::class, 'writeFile']);
        Route::delete('servers/{server}/files', [ServerController::class, 'deleteFile']);
        Route::get('servers/{server}/files/transfer', [ServerController::class, 'transfer']);
    });

    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware('can:act,server,"power"');
});
