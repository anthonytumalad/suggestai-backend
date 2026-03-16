<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\TopicAnalysisController;
use App\Http\Controllers\TopicSessionController;
use App\Http\Controllers\VisualizationController;
use App\Http\Controllers\SuggestionExportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DashboardController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [SigninController::class, 'register']);
    Route::post('/authenticate', [SigninController::class, 'authenticate']);
});

Route::get('/dashboard', [DashboardController::class, 'index']);

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
        Route::put('/{form}', [FormController::class, 'update'])->name('forms.update');
        Route::delete('/{form}', [FormController::class, 'destroy'])->name('forms.destroy');

        Route::prefix('{formId}')->group(function () {
            Route::get('/suggestions',                       [SuggestionController::class, 'index'])->name('forms.suggestions.index');
            Route::delete('/suggestions/{suggestionId}',     [SuggestionController::class, 'destroy']);
            Route::delete('/suggestions',                    [SuggestionController::class, 'bulkDestroy']);

            Route::post('/suggestions/analyze',              [TopicAnalysisController::class, 'analyze'])->name('forms.topics.analyze');
            Route::get('/suggestions/analyze/status',  [TopicAnalysisController::class, 'status']);
            Route::post('/suggestions/save',                 [TopicAnalysisController::class, 'save'])->name('forms.topics.save');
            Route::get('/suggestions/export',                [SuggestionExportController::class, 'export'])->name('forms.suggestions.export');

            Route::get('/topic-sessions',                    [TopicSessionController::class, 'index'])->name('forms.sessions.index');
            Route::get('/topic-sessions/{sessionId}',        [TopicSessionController::class, 'show'])->name('forms.sessions.show');
        });

        Route::prefix('{formId}/sessions/{session}/visualization')->group(function () {
            Route::get('distribution', [VisualizationController::class, 'distribution']);
            Route::get('keywords',     [VisualizationController::class, 'keywords']);
            Route::get('timeline',     [VisualizationController::class, 'timeline']);
            Route::get('stats',        [VisualizationController::class, 'stats']);
        });
    });

    Route::get('/reports',              [ReportController::class, 'index']);
    Route::post('/reports',                  [ReportController::class, 'store']);
    Route::get('/reports/{report}',     [ReportController::class, 'show']);
    Route::get('/reports/{report}/download', [ReportController::class, 'download']);
    Route::delete('/reports/{report}',  [ReportController::class, 'destroy']);
    Route::delete('/reports',           [ReportController::class, 'bulkDestroy']);
});
