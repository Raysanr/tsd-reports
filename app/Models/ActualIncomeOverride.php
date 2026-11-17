<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Actual Income card's typed overrides — see the
 *  create_actual_income_overrides_table migration. */
class ActualIncomeOverride extends Model
{
    public const FIELDS = ['number_of_orders', 'product_cost', 'fulfillment_fee'];

    protected $fillable = ['product_id', 'tsa_id', 'entry_date', 'number_of_orders', 'product_cost', 'fulfillment_fee'];

    protected $casts = [
        'entry_date' => 'date',
        'number_of_orders' => 'float',
        'product_cost' => 'float',
        'fulfillment_fee' => 'float',
    ];
}
