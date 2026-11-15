<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

// Two guards on every route: ability: is what the token may ask for, can: is what its owner
// may do. A token never exceeds its owner.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->middleware('ability:servers:read');

    Route::middleware('can:view,server')->group(function () {
        Route::get('servers/{server}', [ServerController::class, 'show'])->middleware('ability:servers:read');
        Route::get('servers/{server}/console', [ServerController::class, 'console'])->middleware('ability:console:use');
    });

    Route::get('servers/{server}/logs', [ServerController::class, 'logs'])
        ->middleware(['ability:logs:read', 'can:act,server,"logs"']);

    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [ServerController::class, 'backups'])->middleware('ability:backups:read');
        Route::post('servers/{server}/backups', [ServerController::class, 'backup'])->middleware('ability:backups:create');
    });

    Route::middleware('can:act,server,"files"')->group(function () {
        Route::get('servers/{server}/files', [ServerController::class, 'files'])->middleware('ability:files:read');
        Route::put('servers/{server}/files', [ServerController::class, 'writeFile'])->middleware('ability:files:write');
        Route::delete('servers/{server}/files', [ServerController::class, 'deleteFile'])->middleware('ability:files:write');
        // Either is enough: a token that may write could always fetch both addresses.
        Route::get('servers/{server}/files/transfer', [ServerController::class, 'transfer'])->middleware('ability:files:read,files:write');
    });

    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware(['ability:servers:power', 'can:act,server,"power"']);
});
