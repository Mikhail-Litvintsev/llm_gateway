<?php

use App\Http\Controllers\Api\SessionsController;
use App\Http\Controllers\Api\V1\BatchesController;
use App\Http\Controllers\Api\V1\ClientUsageController;
use App\Http\Controllers\Api\V1\FilesController;
use App\Http\Controllers\Api\V1\MessagesBatchAccumulatorController;
use App\Http\Controllers\Api\V1\MessagesController;
use App\Http\Controllers\Api\V1\ModelsController;
use App\Http\Controllers\Api\V1\SkillsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.api_key'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/messages', [MessagesController::class, 'send']);
        Route::post('/messages/async', [MessagesController::class, 'async']);
        Route::post('/messages/count_tokens', [MessagesController::class, 'countTokens']);
        Route::get('/messages/{requestId}', [MessagesController::class, 'show'])
            ->where('requestId', 'req_[A-Za-z0-9]{24}');

        Route::get('/models', [ModelsController::class, 'index']);
        Route::get('/models/{alias}', [ModelsController::class, 'show'])
            ->where('alias', '[a-z0-9-]+');

        Route::post('/batches', [BatchesController::class, 'create']);
        Route::get('/batches', [BatchesController::class, 'index']);
        Route::get('/batches/{batchId}', [BatchesController::class, 'show'])
            ->where('batchId', 'bat_[A-Za-z0-9]{24}');
        Route::get('/batches/{batchId}/results', [BatchesController::class, 'results'])
            ->where('batchId', 'bat_[A-Za-z0-9]{24}');
        Route::post('/batches/{batchId}/cancel', [BatchesController::class, 'cancel'])
            ->where('batchId', 'bat_[A-Za-z0-9]{24}');
        Route::delete('/batches/{batchId}', [BatchesController::class, 'destroy'])
            ->where('batchId', 'bat_[A-Za-z0-9]{24}');

        Route::post('/messages/batch', MessagesBatchAccumulatorController::class);

        Route::post('/files', [FilesController::class, 'upload']);
        Route::get('/files', [FilesController::class, 'index']);
        Route::get('/files/{fileId}', [FilesController::class, 'show'])
            ->where('fileId', 'file_[A-Za-z0-9]{24}');
        Route::delete('/files/{fileId}', [FilesController::class, 'destroy'])
            ->where('fileId', 'file_[A-Za-z0-9]{24}');

        Route::get('/clients/me/usage', [ClientUsageController::class, 'show']);

        Route::post('/skills', [SkillsController::class, 'store']);
        Route::get('/skills', [SkillsController::class, 'index']);
        Route::get('/skills/{skillId}', [SkillsController::class, 'show'])
            ->where('skillId', 'skl_[A-Za-z0-9]{24}');
        Route::delete('/skills/{skillId}', [SkillsController::class, 'destroy'])
            ->where('skillId', 'skl_[A-Za-z0-9]{24}');

        Route::post('/sessions', [SessionsController::class, 'create']);
        Route::get('/sessions/{session}', [SessionsController::class, 'show']);
        Route::delete('/sessions/{session}', [SessionsController::class, 'destroy']);
        Route::get('/sessions/{session}/messages', [SessionsController::class, 'history']);
        Route::post('/sessions/{session}/messages', [SessionsController::class, 'send']);
    });
