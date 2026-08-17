<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'display_name', 'match_keyword', 'match_exclude_keyword', 'pancake_product_ids', 'team', 'sort_order',
    ];

    protected $casts = [
        'is_hidden'            => 'boolean',
        'pancake_product_ids'  => 'array',
    ];

    /** Ported from call-tracker (merged into one app 2026-08-12) — this
     *  product's round-robin roster (see product_tsa's 'position' column
     *  for rotation order). */
    public function tsas(): BelongsToMany
    {
        return $this->belongsToMany(TsaShift::class, 'product_tsa', 'product_id', 'tsa_id')->withPivot('position');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

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

    /** match_exclude_keyword split on commas (trimmed, empties dropped) — same
     *  comma-separated convention as match_keyword/tag_keywords, but with no
     *  display_name fallback (an empty exclude list is the normal case for
     *  every product except SINUXYL — see the match_exclude_keyword
     *  migration's own doc comment for why that one needs it). */
    public function getExcludeKeywordsArrayAttribute(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->match_exclude_keyword ?? ''))));
    }

    /** True if $text (a cart/base product name — NOT a tag, see
     *  isExcludedByItemName()'s own doc comment for why that distinction
     *  matters) contains one of exclude_keywords_array. Checked separately
     *  from matchesText() (not folded into it) because it must only ever be
     *  evaluated against an order's OWN identifying item fields — evaluating
     *  it per-tag as part of matchesText() left a real gap: a stale
     *  "PTERYGIUM" tag left over on an order whose actual item is the
     *  genuinely separate "Pterylief Eye Drops" would pass this check fine
     *  (the bare tag text "PTERYGIUM" doesn't itself contain "PTERYLIEF"),
     *  so the order still matched via the tag loop in matchingOrders()
     *  regardless — confirmed live, order #1351171. */
    public function isExcludedByItemName(?string $text): bool
    {
        if ($text === null || $text === '') return false;

        $normalizedText = self::normalizeForMatch($text);
        foreach ($this->exclude_keywords_array as $exclude) {
            $normalizedExclude = self::normalizeForMatch($exclude);
            if ($normalizedExclude !== '' && str_contains($normalizedText, $normalizedExclude)) {
                return true;
            }
        }
        return false;
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
