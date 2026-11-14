<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServiceStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('service-status', ServiceStatusController::class)->name('service-status');

    Route::middleware('can:manage-servers')->group(function () {
        Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
        Route::get('servers/{server}/configuration', [ServerController::class, 'configuration'])->name('servers.configuration');
        Route::get('servers/{server}/logs', [ServerController::class, 'logs'])->name('servers.logs');
        Route::get('servers/{server}/logs/download', [ServerController::class, 'downloadLog'])->name('servers.logs.download');
        Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
            ->whereIn('action', PowerServer::ACTIONS)
            ->name('servers.power');
    });
});

require __DIR__.'/settings.php';
