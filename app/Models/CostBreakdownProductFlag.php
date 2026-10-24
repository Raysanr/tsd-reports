<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One product's "Cost Allocation Per TSA" checkbox for one Cost Breakdown
 *  month (2026-10-24). Months without a row fall back to the product's own
 *  has_cost_allocation column. */
class CostBreakdownProductFlag extends Model
{
    protected $fillable = ['month', 'product_id', 'has_cost_allocation'];

    protected $casts = ['has_cost_allocation' => 'boolean'];
}
