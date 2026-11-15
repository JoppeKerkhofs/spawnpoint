<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NodeController;
use App\Http\Controllers\Api\V1\PanelController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Two guards on every route: ability: is what the token may ask for, can: is what its owner
// may do. A token never exceeds its owner.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // No ability: an app must be able to ask who it is and what it may do.
    Route::get('me', [MeController::class, 'show']);
    Route::get('options', [MeController::class, 'options'])->middleware('ability:servers:read');

    Route::middleware('can:manage-servers')->group(function () {
        Route::post('servers', [ServerController::class, 'store'])->middleware('ability:servers:manage');
        Route::post('servers/stop-all', [ServerController::class, 'stopAll'])->middleware('ability:servers:power');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->middleware('ability:servers:manage');
        Route::post('servers/{server}/move', [ServerController::class, 'move'])->middleware('ability:servers:manage');
        Route::post('servers/{server}/dns', [ServerController::class, 'dns'])->middleware('ability:servers:manage');
    });

    Route::put('servers/{server}', [ServerController::class, 'update'])
        ->middleware(['ability:servers:configure', 'can:act,server,"configure"']);

    Route::get('servers', [ServerController::class, 'index'])->middleware('ability:servers:read');

    Route::middleware('can:view,server')->group(function () {
        Route::get('servers/{server}', [ServerController::class, 'show'])->middleware('ability:servers:read');
        Route::get('servers/{server}/console', [ServerController::class, 'console'])->middleware('ability:console:use');
    });

    Route::get('servers/{server}/logs', [ServerController::class, 'logs'])
        ->middleware(['ability:logs:read', 'can:act,server,"logs"']);

    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [BackupController::class, 'index'])->middleware('ability:backups:read');
        Route::post('servers/{server}/backups', [BackupController::class, 'store'])->middleware('ability:backups:create');
        Route::delete('servers/{server}/backups/running', [BackupController::class, 'cancel'])->middleware('ability:backups:create');
        Route::put('servers/{server}/backups/retention', [BackupController::class, 'retention'])->middleware('ability:backups:create');
        Route::get('servers/{server}/schedules', [ScheduleController::class, 'index'])->middleware('ability:schedules:manage');
        Route::post('servers/{server}/schedules', [ScheduleController::class, 'store'])->middleware('ability:schedules:manage');
        Route::put('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'update'])->middleware('ability:schedules:manage');
        Route::delete('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'destroy'])->middleware('ability:schedules:manage');

        Route::get('servers/{server}/import', [BackupController::class, 'imports'])->middleware('ability:backups:restore');
        Route::post('servers/{server}/import', [BackupController::class, 'import'])->middleware('ability:backups:restore');

        // The name becomes part of a path on the node, so only the form the node itself writes is routed.
        Route::where(['backup' => '[0-9]{1,12}(\.locked)?\.tar\.gz'])->group(function () {
            Route::get('servers/{server}/backups/{backup}/download', [BackupController::class, 'download'])->middleware('ability:backups:read');
            Route::post('servers/{server}/backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('ability:backups:restore');
            Route::post('servers/{server}/backups/{backup}/lock', [BackupController::class, 'lock'])->middleware('ability:backups:restore');
        });
        // Also the record of a failed backup, which has no archive.
        Route::delete('servers/{server}/backups/{backup}', [BackupController::class, 'destroy'])
            ->where('backup', '[0-9]{1,12}((\.locked)?\.tar\.gz|\.failed)')
            ->middleware('ability:backups:delete');
    });

    Route::middleware('can:act,server,"files"')->group(function () {
        Route::get('servers/{server}/files', [ServerController::class, 'files'])->middleware('ability:files:read');
        Route::put('servers/{server}/files', [ServerController::class, 'writeFile'])->middleware('ability:files:write');
        Route::delete('servers/{server}/files', [ServerController::class, 'deleteFile'])->middleware('ability:files:write');
        Route::post('servers/{server}/files/folder', [FileController::class, 'folder'])->middleware('ability:files:write');
        Route::post('servers/{server}/files/rename', [FileController::class, 'rename'])->middleware('ability:files:write');

        // Either is enough: a token that may write could always fetch both addresses.
        Route::get('servers/{server}/files/transfer', [ServerController::class, 'transfer'])->middleware('ability:files:read,files:write');
    });

    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware(['ability:servers:power', 'can:act,server,"power"']);

    Route::get('servers/{server}/logs/download', [FileController::class, 'log'])
        ->middleware(['ability:logs:read', 'can:act,server,"logs"']);

    Route::middleware('can:manage-users')->group(function () {
        Route::get('nodes', [NodeController::class, 'index'])->middleware('ability:nodes:read');
        Route::post('nodes', [NodeController::class, 'store'])->middleware('ability:nodes:manage');
        Route::put('nodes/{node}', [NodeController::class, 'update'])->middleware('ability:nodes:manage');
        Route::delete('nodes/{node}', [NodeController::class, 'destroy'])->middleware('ability:nodes:manage');
        Route::get('users', [UserController::class, 'index'])->middleware('ability:users:read');
        Route::post('users', [UserController::class, 'store'])->middleware('ability:users:manage');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('ability:users:manage');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('ability:users:manage');
        Route::get('settings', [PanelController::class, 'settings'])->middleware('ability:settings:read');
        Route::put('settings', [PanelController::class, 'saveSettings'])->middleware('ability:settings:manage');
        Route::get('activity', [PanelController::class, 'activity'])->middleware('ability:activity:read');
    });
});
