<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). One row per
 * uploaded call recording — the audio file itself lives on a disk (see
 * Phase 5 of the merge plan: local storage is ephemeral on Railway, this
 * must be pointed at S3 or a persistent volume before real recordings are
 * uploaded in production). Distinct from the pre-existing
 * `call_recording_hours` table, which stores aggregate per-hour durations
 * synced from Google Drive — a different pipeline for a different purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            // Best-effort link, same spirit as call_events.lead_id — matched
            // either directly by phone number or inherited from the
            // nearest-in-time call_event for this TSA.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk_path');
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['tsa_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
