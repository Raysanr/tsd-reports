<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One TSA's Total Sales / Net Income for one month, taken from the Google
 *  Sheet (see the scoreboard_imports migration). */
class ScoreboardImport extends Model
{
    protected $fillable = ['month', 'name', 'team_slug', 'tsa_shift_id', 'sales', 'net', 'source'];

    protected $casts = ['month' => 'date', 'sales' => 'float', 'net' => 'float'];
}
