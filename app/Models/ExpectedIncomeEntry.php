<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product's raw numbers for one DAY on the Expected Income 2026 page —
 *  see the create_expected_income_entries_table migration's own doc
 *  comment for why every field here is manual entry, not a computed rate,
 *  and why storage is per-day (range-summed on read, not a stored MTD row). */
class ExpectedIncomeEntry extends Model
{
    protected $fillable = [
        'product_id', 'entry_date',
        'roas', 'actual_cost_per_lead', 'number_of_leads', 'number_of_orders', 'average_order_value',
        'tax_allocation', 'product_cost',
        'advertising_cost', 'ads_vat', 'ai_expense', 'ad_account_rental_fee', 'shipping_fee', 'product_research',
        'salaries', 'communication_allowance', 'thirteenth_month_allowance', 'sil', 'government_benefits',
        'miscellaneous_expenses', 'magic_fund', 'company_assets', 'executive_benefits', 'office_miscellaneous',
        'maintenance_expenses', 'consultants', 'managers_allowance', 'birthday_cake_allowance', 'water_bill',
        'internet', 'rent', 'electricity', 'geniusmakers_management_fee', 'business_development_fund', 'hmo_expense',
    ];

    protected $casts = [
        'entry_date'           => 'date',
        'roas'                 => 'float',
        'actual_cost_per_lead' => 'float',
        'number_of_leads'      => 'integer',
        'number_of_orders'     => 'integer',
        'average_order_value'  => 'float',
        'tax_allocation'       => 'float',
        'product_cost'         => 'float',
        'advertising_cost'     => 'float',
        'ads_vat'              => 'float',
        'ai_expense'           => 'float',
        'ad_account_rental_fee' => 'float',
        'shipping_fee'          => 'float',
        'product_research'      => 'float',
        'salaries'                    => 'float',
        'communication_allowance'     => 'float',
        'thirteenth_month_allowance'  => 'float',
        'sil'                         => 'float',
        'government_benefits'         => 'float',
        'miscellaneous_expenses'      => 'float',
        'magic_fund'                  => 'float',
        'company_assets'              => 'float',
        'executive_benefits'          => 'float',
        'office_miscellaneous'        => 'float',
        'maintenance_expenses'        => 'float',
        'consultants'                 => 'float',
        'managers_allowance'          => 'float',
        'birthday_cake_allowance'     => 'float',
        'water_bill'                  => 'float',
        'internet'                    => 'float',
        'rent'                        => 'float',
        'electricity'                 => 'float',
        'geniusmakers_management_fee' => 'float',
        'business_development_fund'   => 'float',
        'hmo_expense'                 => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
