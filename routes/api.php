<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ImageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::apiResource('images', ImageController::class)
        ->only(['index', 'show', 'destroy']);

    // Store is separated from the resource to apply the 'upload' rate limiter
    // only on this endpoint — index/show/destroy don't need upload-specific limits.
    Route::post('images', [ImageController::class, 'store'])
        ->middleware('throttle:upload');
});
