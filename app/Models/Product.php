<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'display_name', 'match_keyword', 'team', 'sort_order',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    /** The string actually used for substring matching against a tag or a
     *  cart's product name — falls back to display_name when no shorter
     *  override keyword is set. Replaces the old PRODUCT_TAG_OVERRIDES concept.
     *  With alias support this is just the FIRST keyword; matching code should
     *  use matchesText() so every alias is honored. */
    public function getEffectiveKeywordAttribute(): string
    {
        return $this->keywords_array[0] ?? $this->display_name;
    }

    /** match_keyword split on commas (trimmed, empties dropped), falling back to
     *  [display_name] — e.g. "PTERYGIUM, PteryFix" → ['PTERYGIUM', 'PteryFix'].
     *  Same comma-separated alias convention as TsaShift::tag_keywords. */
    public function getKeywordsArrayAttribute(): array
    {
        $keywords = array_values(array_filter(array_map('trim', explode(',', $this->match_keyword ?? ''))));
        return $keywords ?: [$this->display_name];
    }

    /** True if $text (an order tag or a cart product name) matches ANY of this
     *  product's keywords. Comparison is case-, space- and punctuation-insensitive
     *  ("Clear Sight 3.0" matches keyword "CLEARSIGHT"): both sides are reduced to
     *  bare alphanumerics before the containment check — cart names and tags write
     *  the same product with inconsistent spacing/casing, which is exactly how 114
     *  "Clear Sight 3.0" leads went team-NULL and vanished from every report. */
    public function matchesText(?string $text): bool
    {
        if ($text === null || $text === '') return false;

        $normalizedText = self::normalizeForMatch($text);
        foreach ($this->keywords_array as $keyword) {
            $normalizedKeyword = self::normalizeForMatch($keyword);
            if ($normalizedKeyword !== '' && str_contains($normalizedText, $normalizedKeyword)) {
                return true;
            }
        }
        return false;
    }

    /** Uppercase and strip everything but A-Z/0-9, so containment checks ignore
     *  spacing, punctuation and case differences between keyword and source text. */
    public static function normalizeForMatch(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    }
}
