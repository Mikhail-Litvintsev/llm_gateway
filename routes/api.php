<?php

use App\Http\Controllers\Api\V1\LlmRequestController;
use App\Http\Controllers\Api\V1\RawResponseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth.api_key', 'rate.api_key'])->group(function () {
    Route::post('/llm/request', [LlmRequestController::class, 'store']);
    Route::get('/llm/requests/{requestId}/raw-responses', [RawResponseController::class, 'show'])
        ->where('requestId', '[a-zA-Z0-9_\-:.]+');
});
