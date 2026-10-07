<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Summary Sales Report's own per-date lock (explicit request, 2026-10-07:
 * "in the summary sales report can you add lock icon like in the dsppr")
 * — same exact mechanism as DsPprLockedDate: a standalone table keyed by
 * entry_date alone, not a column on TsaSalesEntry/TsaTiktokEntry. The
 * lock is a property of the DATE itself (every TSA's own real row AND her
 * TikTok Upsell row for that day), not any one TSA's entry, and a date
 * with nothing typed for any TSA yet still needs somewhere to record
 * "this date is locked". Existence of a row = locked; no row = unlocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_locked_dates', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_sales_locked_dates');
    }
};
