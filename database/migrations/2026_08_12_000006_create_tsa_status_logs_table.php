<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Timestamped
 * history behind the TSA Logs page — every status change a TSA makes via
 * the topbar dropdown (Login/Break/DNA Huddle/Coaching/Logout), so their
 * attendance/availability across a shift is visible after the fact, not
 * just as a single current value on tsa_shifts.status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('created_at');

            $table->index(['tsa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_status_logs');
    }
};
