<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            // The Asia/Manila calendar day the completeness check looked at (always
            // "yesterday" relative to ran_at) — kept separate from ran_at so a history
            // page can group/filter by the day being checked, not the day it happened to run.
            $table->date('checked_date')->nullable();
            // Null (not 0) when the completeness check itself couldn't produce a number
            // (Pancake API error, or Pancake reporting zero orders that day) — distinct
            // from a genuine 0, and lets the detail view say "not available" instead of
            // a misleading zero.
            $table->unsignedInteger('local_count')->nullable();
            $table->unsignedInteger('pancake_count')->nullable();
            // Same human-readable strings PancakeReconcile has always produced (and still
            // writes to the reconciliation_issues Setting for the Dashboard banner) — kept
            // here too so the history/detail pages don't need to re-derive them.
            $table->json('issues');
            $table->unsignedInteger('issue_count')->default(0);
            // Denormalized from issue_count > 0 purely so the history list can filter/index
            // on a boolean instead of a JSON column.
            $table->boolean('has_issues')->default(false);
            $table->timestamps();

            $table->index('ran_at');
            $table->index('has_issues');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
