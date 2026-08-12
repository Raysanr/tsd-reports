<?php

use Illuminate\Support\Facades\Route;

// Stateless — a phone's own call-automation app (MacroDroid) hits this
// directly, no browser/session/CSRF token involved. Auth is the per-TSA
// api_token in the request body itself (see CallEventController::store()).
Route::post('/call-events', [App\Http\Controllers\CallEventController::class, 'store']);

// Same stateless, per-TSA api_token auth — hit by whatever uploads the
// finished recording file from the TSA's own PC (see
// CallRecordingController's own doc comment for the full Phone Link + free
// system-audio-recorder pipeline this expects). Note: this is
// App\Http\Controllers\CallRecordingController (app-root, store() only) —
// a DIFFERENT class from App\Http\Controllers\CallTracker\CallRecordingController
// (the session-authed web index/stream pages, routes/web.php).
Route::post('/call-recordings', [App\Http\Controllers\CallRecordingController::class, 'store']);
