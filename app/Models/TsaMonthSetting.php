<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One TSA's team / shift hours / rest day / TikTok flag for one month —
 *  see App\Support\TsaMonths. */
class TsaMonthSetting extends Model
{
    protected $fillable = ['month', 'tsa_shift_id', 'team', 'shift_start', 'shift_end', 'rest_day_of_week', 'tiktok_upsell'];

    protected $casts = ['tiktok_upsell' => 'boolean'];
}
