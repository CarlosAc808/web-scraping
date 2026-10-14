<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\RobotController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/robots', [RobotController::class, 'index']);
    Route::post('/robots', [RobotController::class, 'store']);
    Route::patch('/robots/{robot}', [RobotController::class, 'update']);
    Route::post('/robots/{robot}/executions', [ExecutionController::class, 'store']);
    Route::get('/executions', [ExecutionController::class, 'index']);
    Route::get('/executions/{execution}', [ExecutionController::class, 'show']);
    Route::delete('/executions/{execution}', [ExecutionController::class, 'destroy']);
    Route::get('/price-history', [ExecutionController::class, 'priceHistory']);
});
