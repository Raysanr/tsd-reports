<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class HourFormatter
{
    /** 24-hour int → compact 12-hour label, e.g. 8 => "8:00am", 13 => "1:00pm". */
    public static function label(int $hour): string
    {
        return strtolower(Carbon::createFromTime($hour, 0)->format('g:iA'));
    }

    /** e.g. 8 => "8:00am – 9:00am", 23 => "11:00pm – 12:00am". */
    public static function rangeLabel(int $hour): string
    {
        return self::label($hour) . ' – ' . self::label(($hour + 1) % 24);
    }
}
