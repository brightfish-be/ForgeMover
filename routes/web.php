<?php

use App\Http\Controllers\SetupController;
use App\Http\Middleware\GuardSetup;
use App\Services\SetupStatus;
use Illuminate\Support\Facades\Route;

Route::get('/', function (SetupStatus $status) {
    return redirect($status->complete() ? '/admin' : '/setup');
});

Route::middleware([GuardSetup::class])->prefix('setup')->group(function () {
    Route::get('/', [SetupController::class, 'index'])->name('setup');
    Route::post('/migrate', [SetupController::class, 'migrate'])->name('setup.migrate');
    Route::post('/secrets', [SetupController::class, 'secrets'])->name('setup.secrets');
    Route::post('/admin', [SetupController::class, 'admin'])->name('setup.admin');
    Route::post('/sync', [SetupController::class, 'sync'])->name('setup.sync');
});
