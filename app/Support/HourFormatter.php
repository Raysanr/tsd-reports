<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class HourFormatter
{
    /** 24-hour int → compact 12-hour label, e.g. 8 => "8am", 13 => "1pm". */
    public static function label(int $hour): string
    {
        return strtolower(Carbon::createFromTime($hour, 0)->format('gA'));
    }

    /** e.g. 8 => "8am – 9am", 23 => "11pm – 12am". */
    public static function rangeLabel(int $hour): string
    {
        return self::label($hour) . ' – ' . self::label(($hour + 1) % 24);
    }
}
