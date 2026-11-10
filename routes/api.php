<?php

use Illuminate\Support\Facades\Route;

// Stateless — a phone's own call-automation app (MacroDroid) hits this
// directly, no browser/session/CSRF token involved. Auth is the per-TSA
// api_token in the request body itself (see CallEventController::store()).
Route::post('/call-events', [App\Http\Controllers\CallEventController::class, 'store']);

// External read-only Telesales API for other departments. Bearer token
// (TELESALES_API_TOKEN), see TelesalesApiToken.
Route::get('/external/v1/telesales/daily', App\Http\Controllers\Api\TelesalesDailyController::class)
    ->middleware([App\Http\Middleware\TelesalesApiToken::class, 'throttle:60,1'])
    ->name('api.telesales.daily');
