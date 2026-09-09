<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manually-entered daily whiteboard summary (explicit request, 2026-09-09:
 * replace the Dashboard's Recent Orders card with an editable card matching
 * the physical "Telesales Department" whiteboard photo — gross sales, daily
 * net income, top seller, top team, per-sub-team working counts, overall
 * working TSA count). One row per calendar date; editing an existing date
 * overwrites that row rather than creating a new one.
 *
 * sub_team_counts is JSON (not fixed columns) because the whiteboard's own
 * sub-team rows (e.g. "Team Lhiza", "Team Gretchen") are whatever names are
 * handwritten that day, not a fixed roster of two — the UI supports adding/
 * removing rows freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telesales_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date')->unique();
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('net_income', 12, 2)->default(0);
            $table->string('top_seller_name')->nullable();
            $table->decimal('top_seller_gross_sales', 12, 2)->default(0);
            $table->decimal('top_seller_net_income', 12, 2)->default(0);
            $table->string('top_team_name')->nullable();
            $table->decimal('top_team_gross_sales', 12, 2)->default(0);
            $table->decimal('top_team_net_income', 12, 2)->default(0);
            $table->json('sub_team_counts')->nullable();
            $table->unsignedInteger('overall_working_tsas')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telesales_summaries');
    }
};
