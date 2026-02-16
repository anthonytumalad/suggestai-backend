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
        Route::get('/', [FormController::class, 'index'])
            ->name('forms.index');

        Route::post('/', [FormController::class, 'store'])
            ->name('forms.store');

        Route::get('/{form}', [FormController::class, 'showById'])
            ->name('forms.showById');

        Route::prefix('{formId}/suggestions')->group(function () {
            Route::get('/', [SuggestionController::class, 'index'])
                ->name('forms.suggestions');

            Route::post('/analyze', [SuggestionController::class, 'analyzeTopics'])
                ->name('forms.analyze');

            Route::post('/suggestions/topic-sessions', [SuggestionController::class, 'saveTopicSession'])
                ->name('forms.topic-session-save');

            Route::get('/topic-sessions', [SuggestionController::class, 'getTopicSessions'])
                ->name('forms.topic-sessions');

            Route::get('/topic-sessions/{sessionId}', [SuggestionController::class, 'getTopicSessionDetails'])
                ->name('forms.topic-session-details');

            Route::delete('/topic-sessions/{sessionId}', [SuggestionController::class, 'deleteTopicSession'])
                ->name('forms.topic-session-delete');

            Route::get('/topics/{topicId}/suggestions', [SuggestionController::class, 'getSuggestionsByTopic'])
                ->name('forms.topic-suggestions');
        });
    });
});
