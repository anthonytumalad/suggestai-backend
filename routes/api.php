<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\TopicAnalysisController;
use App\Http\Controllers\TopicSessionController;
use App\Http\Controllers\VisualizationController;
use App\Http\Controllers\SuggestionExportController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [SigninController::class, 'register']);
    Route::post('/authenticate', [SigninController::class, 'authenticate']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/signout',    [SigninController::class, 'signout']);
        Route::post('/signoutAll', [SigninController::class, 'signoutAll']);
        Route::post('/refresh',    [SigninController::class, 'refresh']);
        Route::get('/me',          [SigninController::class, 'me']);
    });

    Route::prefix('forms')->group(function () {
        Route::get('/',       [FormController::class, 'index'])->name('forms.index');
        Route::post('/',      [FormController::class, 'store'])->name('forms.store');
        Route::get('/{form}', [FormController::class, 'showById'])->name('forms.showById');

        Route::prefix('{formId}')->group(function () {
            Route::get('/suggestions', [SuggestionController::class, 'index'])->name('forms.suggestions.index');
            Route::post('/suggestions/analyze', [TopicAnalysisController::class, 'analyze'])->name('forms.topics.analyze');
            Route::post('/suggestions/save', [TopicAnalysisController::class, 'save'])->name('forms.topics.save');
            Route::get('/topic-sessions', [TopicSessionController::class, 'index'])->name('forms.sessions.index');
            Route::get('/topic-sessions/{sessionId}', [TopicSessionController::class, 'show'])->name('forms.sessions.show');
            Route::get('/suggestions/export', [SuggestionExportController::class, 'export'])->name('forms.suggestions.export');
        });

        Route::prefix('{formId}/sessions/{session}/visualization')->group(function () {
            Route::get('distribution', [VisualizationController::class, 'distribution']);
            Route::get('keywords',     [VisualizationController::class, 'keywords']);
            Route::get('timeline',     [VisualizationController::class, 'timeline']);
            Route::get('stats',        [VisualizationController::class, 'stats']);
        });
    });
});
