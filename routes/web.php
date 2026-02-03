<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FormController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\SuggestionController;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Landing');
})->name('landing');

Route::middleware('student.auth')->group(function () {
    Route::get('/forms/{slug}', [FormController::class, 'show'])
        ->name('forms.show');

    Route::post('/forms/{slug}/submit', [SuggestionController::class, 'store'])
        ->name('suggestions.store');
});

Route::get('/forms/{slug}/qrcode', [FormController::class, 'qrcode'])
    ->name('forms.qrcode');

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirectToGoogle'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])
    ->name('google.callback');
