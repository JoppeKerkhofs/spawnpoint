<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('servers', [ServerController::class, 'index']);

    // The role is checked here, the token's ability in the controller: a token never exceeds its owner.
    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware('can:manage-servers');
});
