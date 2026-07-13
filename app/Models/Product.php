<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'display_name', 'match_keyword', 'team', 'sort_order',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    /** The string actually used for substring matching against a tag or a
     *  cart's product name — falls back to display_name when no shorter
     *  override keyword is set. Replaces the old PRODUCT_TAG_OVERRIDES concept. */
    public function getEffectiveKeywordAttribute(): string
    {
        return $this->match_keyword ?: $this->display_name;
    }
}
