<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TsaShift extends Model
{
    protected $fillable = [
        'tsa_key', 'pos_user_id', 'display_name', 'team', 'tag_keywords', 'seller_keywords',
        'shift_start', 'shift_end', 'sort_order', 'rest_day_of_week',
    ];

    public function restDays()
    {
        return $this->hasMany(TsaRestDay::class);
    }

    /**
     * Whether this TSA is off on $date. An explicit tsa_rest_days row (either an
     * extra day off, or an override back to working) always wins over the
     * recurring rule; otherwise falls back to whether $date's weekday matches
     * rest_day_of_week.
     *
     * Deliberately does NOT use Collection::firstWhere('date', ...) — the `date`
     * attribute is Carbon-cast, and firstWhere's loose `==` comparison against a
     * plain date string compares Carbon's default __toString() ("Y-m-d H:i:s")
     * against a "Y-m-d" string, which never matches. Compares toDateString()
     * explicitly instead.
     */
    public function isOffOn(\Illuminate\Support\Carbon $date): bool
    {
        $override = $this->restDays->first(
            fn (TsaRestDay $r) => $r->date->toDateString() === $date->toDateString()
        );

        if ($override !== null) {
            return $override->is_off;
        }

        return $this->rest_day_of_week !== null
            && strtolower($date->format('l')) === $this->rest_day_of_week;
    }

    public function getShiftRangeAttribute(): string
    {
        if (!$this->shift_start && !$this->shift_end) return '—';
        $fmt  = fn($t) => date('g:iA', strtotime($t));
        $parts = array_filter([$this->shift_start, $this->shift_end]);
        return implode(' - ', array_map($fmt, $parts));
    }

    /** Comma-separated tag_keywords -> trimmed array, e.g. "KATH,KATHLEEN" -> ['KATH','KATHLEEN']. */
    public function getTagKeywordsArrayAttribute(): array
    {
        return self::splitKeywords($this->tag_keywords);
    }

    public function getSellerKeywordsArrayAttribute(): array
    {
        return self::splitKeywords($this->seller_keywords);
    }

    /** tag_keywords minus the auto-included base (uppercased tsa_key) — what the
     *  "Also matches" field should show/edit, since the base is always re-added. */
    public function getExtraTagKeywordsAttribute(): string
    {
        $extra = array_diff($this->tag_keywords_array, [strtoupper($this->tsa_key)]);
        return implode(', ', $extra);
    }

    private static function splitKeywords(?string $csv): array
    {
        if (!$csv) return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}
