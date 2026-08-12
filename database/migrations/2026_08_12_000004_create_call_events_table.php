<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). One row per
 * real call a TSA's own phone reports via its own call-log automation
 * (MacroDroid → CallEventController::store()) — the source data for a
 * load-reimbursement report, since no telco exposes a way to read a
 * personal prepaid SIM's balance from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            // Matched best-effort against a lead of this TSA's own by phone
            // number — null when no lead matches (wrong number, personal
            // call, stale/duplicate lead).
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_number');
            $table->enum('direction', ['outgoing', 'incoming', 'missed']);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tsa_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_events');
    }
};
