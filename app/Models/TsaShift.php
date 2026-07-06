<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TsaShift extends Model
{
    protected $fillable = [
        'tsa_key', 'pos_user_id', 'display_name', 'team', 'tag_keywords', 'seller_keywords',
        'shift_start', 'shift_end', 'sort_order',
    ];

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
