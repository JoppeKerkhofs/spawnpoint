<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ModpackSearchController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\PanelSettingsController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
// Asked by the panel's own web server before it passes browser traffic on to a node.
Route::get('node-upstream/{node}', [NodeController::class, 'upstream'])->whereNumber('node')->name('nodes.upstream');
// Public: anyone can read what the panel keeps about them, signed in or not.
Route::get('privacy', PrivacyController::class)->name('privacy');
Route::get('support', SupportController::class)->name('support');

Route::middleware(['auth', 'verified', RequireTwoFactor::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('service-status', ServiceStatusController::class)->name('service-status');
    Route::get('api-docs', ApiDocsController::class)->name('api-docs');
    Route::get('api-docs/openapi.json', ApiDocsController::class)->name('api-docs.spec');

    // Creating and deleting servers is for admins. Everything else on a server goes by the
    // permissions of the share: an admin has them all, a viewer the ones ticked on the Users page.
    Route::middleware('can:manage-servers')->group(function () {
        Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::post('servers/stop-all', [ServerController::class, 'stopAll'])->name('servers.stop-all');
        Route::post('servers/{server}/dns', [ServerController::class, 'dns'])->name('servers.dns');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    });

    Route::middleware('can:search-modpacks')->group(function () {
        Route::get('modpacks/search', ModpackSearchController::class)->name('modpacks.search');
        Route::get('modpacks/versions', [ModpackSearchController::class, 'versions'])->name('modpacks.versions');
    });

    Route::middleware('can:act,server,"configure"')->group(function () {
        Route::get('servers/{server}/configuration', [ServerController::class, 'configuration'])->name('servers.configuration');
        Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    });

    Route::middleware('can:act,server,"files"')->group(function () {
        Route::get('servers/{server}/files', [FileController::class, 'index'])->name('servers.files');
        Route::put('servers/{server}/files/content', [FileController::class, 'save'])->name('servers.files.save');
        Route::post('servers/{server}/files/folder', [FileController::class, 'folder'])->name('servers.files.folder');
        Route::post('servers/{server}/files/rename', [FileController::class, 'rename'])->name('servers.files.rename');
        Route::delete('servers/{server}/files', [FileController::class, 'destroy'])->name('servers.files.destroy');
        Route::get('servers/{server}/files/download', [FileController::class, 'download'])->name('servers.files.download');
    });

    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [BackupController::class, 'index'])->name('servers.backups');
        Route::post('servers/{server}/backups', [BackupController::class, 'store'])->name('servers.backups.store');
        Route::delete('servers/{server}/backups/running', [BackupController::class, 'cancel'])->name('servers.backups.cancel');
        Route::put('servers/{server}/backups/retention', [BackupController::class, 'retention'])->name('servers.backups.retention');
        Route::post('servers/{server}/schedules', [ScheduleController::class, 'store'])->name('servers.schedules.store');
        Route::put('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'update'])->name('servers.schedules.update');
        Route::delete('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('servers.schedules.destroy');
        Route::post('servers/{server}/import', [BackupController::class, 'import'])->name('servers.import');
        // The name becomes part of a path on the node, so only the form the node itself writes is routed.
        Route::where(['backup' => '[0-9]{1,12}(\.locked)?\.tar\.gz'])->group(function () {
            Route::post('servers/{server}/backups/{backup}/restore', [BackupController::class, 'restore'])->name('servers.backups.restore');
            Route::post('servers/{server}/backups/{backup}/lock', [BackupController::class, 'lock'])->name('servers.backups.lock');
            Route::get('servers/{server}/backups/{backup}/download', [BackupController::class, 'download'])->name('servers.backups.download');
        });
        // Also the record of a failed backup, which has no archive.
        Route::delete('servers/{server}/backups/{backup}', [BackupController::class, 'destroy'])
            ->where('backup', '[0-9]{1,12}((\.locked)?\.tar\.gz|\.failed)')
            ->name('servers.backups.destroy');
    });

    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware('can:act,server,"power"')
        ->name('servers.power');

    Route::middleware('can:act,server,"logs"')->group(function () {
        Route::get('servers/{server}/logs', [ServerController::class, 'logs'])->name('servers.logs');
        Route::get('servers/{server}/logs/download', [ServerController::class, 'downloadLog'])->name('servers.logs.download');
    });

    // After servers/create above, so "create" is never taken for a server name.
    Route::get('servers/{server}', [ServerController::class, 'show'])->middleware('can:view,server')->name('servers.show');
});

Route::middleware(['auth', 'verified', RequireTwoFactor::class, 'can:manage-users'])->group(function () {
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('panel-settings', [PanelSettingsController::class, 'edit'])->name('panel-settings.edit');
    Route::put('panel-settings', [PanelSettingsController::class, 'update'])->name('panel-settings.update');
    Route::get('nodes', [NodeController::class, 'index'])->name('nodes.index');
    Route::post('nodes', [NodeController::class, 'store'])->name('nodes.store');
    Route::put('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
    Route::delete('nodes/{node}', [NodeController::class, 'destroy'])->name('nodes.destroy');
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/settings.php';
