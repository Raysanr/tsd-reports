<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TeamReportController;
use App\Http\Controllers\TsaPerformanceController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TsaManagementController;

Route::get('/',                [DashboardController::class,      'index'])->name('dashboard');
Route::post('/sync',           [DashboardController::class,      'sync'])->name('dashboard.sync');
Route::get('/team-report',     [TeamReportController::class,     'index'])->name('team-report');
Route::get('/tsa-performance', [TsaPerformanceController::class, 'index'])->name('tsa-performance');
Route::get('/charts',          [ChartsController::class,         'index'])->name('charts');

Route::get('/tsa-management',             [TsaManagementController::class, 'index'])->name('tsa-management');
Route::get('/tsa-management/pos-users',   [TsaManagementController::class, 'searchPosUsers'])->name('tsa-management.pos-users');
Route::post('/tsa-management',            [TsaManagementController::class, 'store'])->name('tsa-management.store');
Route::put('/tsa-management/{tsaShift}',  [TsaManagementController::class, 'update'])->name('tsa-management.update');
Route::delete('/tsa-management/{tsaShift}', [TsaManagementController::class, 'destroy'])->name('tsa-management.destroy');

Route::get('/settings',          [SettingsController::class, 'index'])->name('settings');
Route::post('/settings',         [SettingsController::class, 'save'])->name('settings.save');
Route::post('/settings/detect',  [SettingsController::class, 'detect'])->name('settings.detect');
Route::post('/settings/clear',   [SettingsController::class, 'clear'])->name('settings.clear');
Route::post('/settings/shifts',  [SettingsController::class, 'saveShifts'])->name('settings.shifts');
