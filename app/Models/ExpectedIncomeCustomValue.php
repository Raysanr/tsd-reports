<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One product's typed-in dollar value for one custom row (added via the +
 *  icon on Projections) on one calendar day — see
 *  create_expected_income_custom_values_table's own doc comment for why
 *  this exists as a separate flexible table rather than a real column on
 *  expected_income_entries. Keyed by ProjectionCustomRow's own stable
 *  `key`, never its label or id. tsa_id is nullable, same NULL-means-
 *  product-level-total convention as ExpectedIncomeEntry's own tsa_id. */
class ExpectedIncomeCustomValue extends Model
{
    protected $fillable = ['product_id', 'tsa_id', 'entry_date', 'custom_row_key', 'value'];

    protected $casts = [
        'entry_date' => 'date',
        'value'      => 'decimal:2',
    ];
}
