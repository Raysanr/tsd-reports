<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income 2026 (explicit request, 2026-09-26:
 * "analyze this expected income and add it to the data management module").
 * One row per (product, month) — the source sheet's own "EXPECTED INCOME
 * 2026" tab, a month-to-date P&L summary per product (Clearsight, Pterygium,
 * Sinuxyl, ...), with Team Eyecare/Team SH Naturals rows derived as the sum
 * of their own products (same convention as ProjectionColumn's Telesales
 * Department = Opening + Closing).
 *
 * Every field below is manual entry, not a computed rate, per explicit
 * decision this session after verifying several formulas against the real
 * sheet's own Clearsight row (Leads 647, Orders 112, AOV 804.46): Conversion
 * Rate/Gross Sales/Cancelled/Returns/Delivered all matched Projections'
 * shared-rate chain exactly, but Tax Allocation and Product Cost did NOT —
 * Tax Allocation repeated the identical peso figure across unrelated
 * products (a fixed pool, not a %-of-Gross-Sales rate) and Product Cost
 * varied uniquely per product with no common rate at all. Since neither
 * could be verified, and Selling & Marketing/Operating Costs were never
 * checked against real values on THIS tab either, every one of those rows
 * is captured as typed here — same "don't guess a second formula wrong"
 * caution as dsppr_entries' own net_income field.
 *
 * ROAS and Actual Cost Per Lead are ALSO manual — the sheet shows non-zero
 * values for both while Advertising Cost reads 0.00 everywhere in the
 * sample data, so neither can be back-derived from what's visible.
 *
 * COD Fee and Fulfillment Fee are the one exception: NOT stored here,
 * because they're confirmed computed formulas already verified on
 * Projections (Delivered × 2.24%, Orders × ₱25 flat) — same real formulas,
 * reused as-is, per explicit decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expected_income_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('month');

            $table->decimal('roas', 10, 2)->default(0);
            $table->decimal('actual_cost_per_lead', 10, 2)->default(0);
            $table->unsignedInteger('number_of_leads')->default(0);
            $table->unsignedInteger('number_of_orders')->default(0);
            $table->decimal('average_order_value', 12, 2)->default(0);

            $table->decimal('tax_allocation', 14, 2)->default(0);
            $table->decimal('product_cost', 14, 2)->default(0);

            // Selling And Marketing — manual (COD Fee/Fulfillment Fee excluded, computed).
            $table->decimal('advertising_cost', 14, 2)->default(0);
            $table->decimal('ads_vat', 14, 2)->default(0);
            $table->decimal('ai_expense', 14, 2)->default(0);
            $table->decimal('ad_account_rental_fee', 14, 2)->default(0);
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->decimal('product_research', 14, 2)->default(0);

            // Operating Costs — manual, same 21 rows as ProjectionCalculator::OPERATING_COST_ROWS.
            $table->decimal('salaries', 14, 2)->default(0);
            $table->decimal('communication_allowance', 14, 2)->default(0);
            $table->decimal('thirteenth_month_allowance', 14, 2)->default(0);
            $table->decimal('sil', 14, 2)->default(0);
            $table->decimal('government_benefits', 14, 2)->default(0);
            $table->decimal('miscellaneous_expenses', 14, 2)->default(0);
            $table->decimal('magic_fund', 14, 2)->default(0);
            $table->decimal('company_assets', 14, 2)->default(0);
            $table->decimal('executive_benefits', 14, 2)->default(0);
            $table->decimal('office_miscellaneous', 14, 2)->default(0);
            $table->decimal('maintenance_expenses', 14, 2)->default(0);
            $table->decimal('consultants', 14, 2)->default(0);
            $table->decimal('managers_allowance', 14, 2)->default(0);
            $table->decimal('birthday_cake_allowance', 14, 2)->default(0);
            $table->decimal('water_bill', 14, 2)->default(0);
            $table->decimal('internet', 14, 2)->default(0);
            $table->decimal('rent', 14, 2)->default(0);
            $table->decimal('electricity', 14, 2)->default(0);
            $table->decimal('geniusmakers_management_fee', 14, 2)->default(0);
            $table->decimal('business_development_fund', 14, 2)->default(0);
            $table->decimal('hmo_expense', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_income_entries');
    }
};
