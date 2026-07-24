<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real per-hour call-duration totals, synced from the Google Drive folders
 * each TSA's device auto-uploads recordings into (see SyncCallRecordings).
 * TsaPerformanceController::showTsa() reads this — when a row exists for a
 * given tsa_key/date/hour, its total_seconds replaces the old flat "3 minutes
 * per answered call" OPT assumption for that hour; hours with no row yet
 * (recordings not synced, or before this feature existed) fall back to the
 * formula unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recording_hours', function (Blueprint $table) {
            $table->id();
            $table->string('tsa_key');
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('call_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tsa_key', 'date', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recording_hours');
    }
};
