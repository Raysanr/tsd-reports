<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSPPR - TSM Report's daily-entry table lock, moved from one whole-table
 * Setting flag to PER DATE (explicit follow-up, 2026-10-07: "i want to
 * make it per date like the lock icon is in the dates right side") —
 * supersedes the earlier 2026-10-07 "one lock for the whole table"
 * decision (DsPprReportController::LOCK_SETTING_KEY), now removed.
 *
 * A standalone table keyed by entry_date ALONE, not a column on
 * dsppr_entries — the lock is a property of the DATE itself (every
 * product's own row for that day, plus TIKTOK ORDERS), not any one
 * product's entry, and a date with nothing typed for any product yet
 * still needs somewhere to record "this date is locked" (no
 * DsPprEntry row would exist to attach a column to in that case).
 * Existence of a row = locked; no row = unlocked — no boolean column
 * needed, same "existence as the flag" convention this app could use
 * elsewhere but hasn't needed until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsppr_locked_dates', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsppr_locked_dates');
    }
};
