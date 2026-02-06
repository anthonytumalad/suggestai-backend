<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\SuggestionController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [SigninController::class, 'register']);
    Route::post('/authenticate', [SigninController::class, 'authenticate']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/signout', [SigninController::class, 'signout']);
        Route::post('/signoutAll', [SigninController::class, 'signoutAll']);
        Route::post('/refresh', [SigninController::class, 'refresh']);
        Route::get('/me', [SigninController::class, 'me']);
    });

    Route::prefix('forms')->group(function () {
        Route::get('/', [FormController::class, 'index'])->name('forms.index');
        Route::post('/', [FormController::class, 'store'])->name('forms.store');
        Route::get('/forms/{form}', [FormController::class, 'showById'])->name('forms.showById');

        Route::get('{form}/suggestions', [SuggestionController::class, 'index'])->name('forms.suggestions');
        Route::post('{formId}/suggestions/analyze', [SuggestionController::class, 'analyzeTopics'])->name('forms.analyze');
    });
});
