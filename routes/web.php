<?php

use App\Http\Controllers\Internal\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal')->middleware('internal.network')->group(function () {
    Route::get('/health', [MonitoringController::class, 'health']);
    Route::get('/stats', [MonitoringController::class, 'stats']);
});

