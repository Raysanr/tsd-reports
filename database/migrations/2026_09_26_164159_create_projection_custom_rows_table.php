<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-26: "add
 * like + icon on Selling And Marketing and Operating Costs ... has modal
 * ... if it is editable or fixed"). A ROW DEFINITION, not a per-column
 * value — same relationship to ProjectionCalculator::SELLING_COST_ROWS/
 * OPERATING_COST_ROWS as a row added here has to Settings' own rate
 * storage: one shared row (this table) + one shared %-of-Gross-Sales rate
 * (Settings, key "projection_rate.custom_<id>") that every column's own
 * P&L reads, exactly like the built-in Advertising Cost/Salaries/etc.
 * rows already do (explicit decision, 2026-09-26, after the user clarified
 * a custom row must "reflect to all" cards the same way built-in rows do).
 *
 * `key` is a stable slug ("custom_<id>" pattern, generated after insert)
 * used everywhere a built-in row's array key is used today (selling_lines/
 * operating_lines, the editable-rate endpoint, etc.) — never the row's own
 * autoincrement id directly, so renaming a row's label later can't ever
 * orphan its stored rate.
 *
 * `is_fixed` mirrors NON_EDITABLE_SELLING_ROWS' own meaning (COD Fee/
 * Fulfillment Fee) — true means never editable via a dollar box on
 * Opening/Closing Shift, its %-of-Gross-Sales rate is set ONLY through the
 * add/edit modal, not the inline row itself.
 *
 * + icon lives ONLY on Opening Shift/Closing Shift (explicit decision,
 * 2026-09-26) — every OTHER column (Telesales Department, every Individual
 * TSA card) just displays whatever rows exist here read-only, same as
 * every built-in row already behaves on those cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_custom_rows', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->enum('section', ['selling', 'operating']);
            $table->string('label');
            $table->boolean('is_fixed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projection_custom_rows');
    }
};
