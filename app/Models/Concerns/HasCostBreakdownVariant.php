<?php

namespace App\Models\Concerns;

use App\Support\CostBreakdownVariant;
use Illuminate\Database\Eloquent\Builder;

/** Scopes a Cost Breakdown table to the request's variant (budget/actual —
 *  see CostBreakdownVariant) and stamps it on every new row. Existing code
 *  keeps reading the budget rows with no change of its own. */
trait HasCostBreakdownVariant
{
    protected static function bootHasCostBreakdownVariant(): void
    {
        static::addGlobalScope('cost_breakdown_variant', function (Builder $query) {
            $query->where($query->getModel()->getTable() . '.variant', CostBreakdownVariant::current());
        });

        static::creating(function ($row) {
            $row->variant ??= CostBreakdownVariant::current();
        });
    }
}
