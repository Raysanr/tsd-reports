<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Variation id -> Product Costs row on the Product Costs page. */
class ProductCost extends Model
{
    protected $fillable = ['variation', 'cost'];

    protected $casts = [
        'cost' => 'float',
    ];
}
