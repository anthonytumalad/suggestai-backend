<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SigninController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [SigninController::class, 'register']);
    Route::post('/signin', [SigninController::class, 'authenticate']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [SigninController::class, 'logout']);
        Route::post('/logout-all', [SigninController::class, 'logoutAll']);
        Route::post('/refresh', [SigninController::class, 'refresh']);
        Route::get('/me', [SigninController::class, 'me']);
    });
});
