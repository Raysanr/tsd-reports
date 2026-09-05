<?php

use Illuminate\Support\Facades\Route;

// Stateless — a phone's own call-automation app (MacroDroid) hits this
// directly, no browser/session/CSRF token involved. Auth is the per-TSA
// api_token in the request body itself (see CallEventController::store()).
Route::post('/call-events', [App\Http\Controllers\CallEventController::class, 'store']);
