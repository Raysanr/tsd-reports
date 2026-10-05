<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected Income's own 2 fixed TikTok cards — "TIKTOK: SH NATURALS" and
 * "TIKTOK: NATUREVA" (explicit request, 2026-10-05, real sheet
 * screenshot) — shown only in a TikTok-flagged TSA's own card stack
 * (TsaShift.tiktok_upsell, same flag as Summary Sales Report's TikTok
 * Upsell section) and in the page's overall TOTAL. NOT a real Product —
 * no Cost Breakdown/Tax Allocation automation applies (explicit
 * confirmation: fully manual, every field typed in), so this is its own
 * table rather than reusing expected_income_entries (which assumes a
 * real product_id and carries TSA-scoped override machinery this doesn't
 * need). card_key distinguishes the 2 fixed cards ('sh_naturals' /
 * 'natureva') — not a foreign key, since there's no Product/team record
 * backing either name.
 *
 * Same column shape as expected_income_entries minus product_id/
 * is_locked (no lock toggle on a manual-only card) plus card_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expected_income_tiktok_entries', function (Blueprint $table) {
            $table->id();
            $table->string('card_key');
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->date('entry_date');

            $table->decimal('roas', 10, 2)->default(0);
            $table->decimal('standard_cost_per_message', 10, 2)->default(0);
            $table->decimal('actual_cost_per_lead', 10, 2)->default(0);
            $table->unsignedInteger('number_of_leads')->default(0);
            $table->unsignedInteger('number_of_orders')->default(0);
            $table->decimal('average_order_value', 12, 2)->default(0);
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('cancelled', 12, 2)->default(0);
            $table->decimal('returns', 12, 2)->default(0);
            $table->decimal('delivered', 12, 2)->default(0);
            $table->decimal('tax_allocation', 14, 2)->default(0);
            $table->decimal('product_cost', 14, 2)->default(0);
            $table->decimal('advertising_cost', 14, 2)->default(0);
            $table->decimal('ads_vat', 14, 2)->default(0);
            $table->decimal('ai_expense', 14, 2)->default(0);
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->decimal('product_research', 14, 2)->default(0);
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

            $table->unique(['card_key', 'tsa_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_income_tiktok_entries');
    }
};
