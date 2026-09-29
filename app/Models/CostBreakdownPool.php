<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One shared monthly cost pool on the Cost Breakdown page (Communication
 *  Allowance, 13th Month Allowance, ... HMO Expense) — see
 *  create_cost_breakdown_pools_table's own doc comment for why every pool
 *  here is a flat manual monthly amount, split across TSAs by
 *  CostBreakdownCalculator, not stored per-TSA. */
class CostBreakdownPool extends Model
{
    protected $fillable = ['key', 'label', 'amount', 'sort_order'];

    protected $casts = [
        'amount' => 'float',
        'sort_order' => 'integer',
    ];

    /** The real sheet's own 21 pools, in its own display order, with its
     *  own starting amounts — self-heals an empty table the same way
     *  CostBreakdownRole::ensureSeeded() does. */
    public const SEED_POOLS = [
        ['key' => 'communication_allowance', 'label' => 'Communication Allowance', 'amount' => 500.42, 'sort_order' => 0],
        ['key' => 'thirteenth_month_allowance', 'label' => '13th Month Allowance', 'amount' => 5801.19, 'sort_order' => 1],
        ['key' => 'sil', 'label' => 'SIL', 'amount' => 2668.92, 'sort_order' => 2],
        ['key' => 'government_benefits', 'label' => 'Government Benefits', 'amount' => 5922.47, 'sort_order' => 3],
        ['key' => 'miscellaneous_expenses', 'label' => 'Miscellaneous expenses', 'amount' => 3760.38, 'sort_order' => 4],
        ['key' => 'product_research', 'label' => 'Product Research', 'amount' => 625.00, 'sort_order' => 5],
        ['key' => 'magic_fund', 'label' => 'Magic Fund', 'amount' => 416.67, 'sort_order' => 6],
        ['key' => 'company_assets', 'label' => 'Company Assets', 'amount' => 2522.68, 'sort_order' => 7],
        ['key' => 'executive_benefits', 'label' => 'Executive Benefits', 'amount' => 2416.67, 'sort_order' => 8],
        ['key' => 'office_miscellaneous', 'label' => 'Office Miscellaneous', 'amount' => 1216.67, 'sort_order' => 9],
        ['key' => 'maintenance_expenses', 'label' => 'Maintenance Expenses', 'amount' => 2241.67, 'sort_order' => 10],
        ['key' => 'consultants', 'label' => 'Consultants', 'amount' => 645.83, 'sort_order' => 11],
        ['key' => 'managers_allowance', 'label' => "Manager's Allowance", 'amount' => 833.33, 'sort_order' => 12],
        ['key' => 'birthday_cake_allowance', 'label' => 'Birthday Cake Allowance', 'amount' => 75.00, 'sort_order' => 13],
        ['key' => 'water_bill', 'label' => 'Water Bill', 'amount' => 25.42, 'sort_order' => 14],
        ['key' => 'internet', 'label' => 'Internet', 'amount' => 566.54, 'sort_order' => 15],
        ['key' => 'rent', 'label' => 'Rent', 'amount' => 4706.25, 'sort_order' => 16],
        ['key' => 'electricity', 'label' => 'Electricity', 'amount' => 4500.00, 'sort_order' => 17],
        // Order and amounts confirmed against the sheet's own raw CSV
        // export (2026-09-29), not a screenshot read — the rendered sheet's
        // own per-TSA table has a genuine formula bug starting at
        // Consultants (each column there shows the PREVIOUS pool's own
        // amount ÷ headcount, not its own), confirmed by cross-checking the
        // raw CSV numbers directly. This app deliberately does NOT
        // replicate that bug — see CostBreakdownCalculator's own doc
        // comment.
        ['key' => 'business_development_fund', 'label' => 'Business Development Fund', 'amount' => 2083.33, 'sort_order' => 18],
        ['key' => 'geniusmakers_management_fee', 'label' => 'Geniusmakers Management Fee', 'amount' => 4166.67, 'sort_order' => 19],
        ['key' => 'hmo_expense', 'label' => 'HMO Expense', 'amount' => 4642.75, 'sort_order' => 20],
    ];

    /** Creates any missing pool (with its full seed values, amount
     *  included) AND re-syncs label/sort_order on an ALREADY-EXISTING pool
     *  on every call — same "only if empty" fragility CostBreakdownRole::
     *  ensureSeeded() had, root-caused live, 2026-09-29, fixed here too.
     *  amount is deliberately EXCLUDED from the re-sync (existing rows
     *  only) — it's the one field this app's own UI actually lets someone
     *  type a real edit into. */
    public static function ensureSeeded(): void
    {
        foreach (self::SEED_POOLS as $seed) {
            $pool = static::firstOrCreate(['key' => $seed['key']], $seed);
            $pool->fill(collect($seed)->except(['key', 'amount'])->all());
            if ($pool->isDirty()) {
                $pool->save();
            }
        }
    }
}
