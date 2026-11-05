<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** # of TSA on Sales Summary's Daily Sales Report: one department on one day. */
class OverallTsaCount extends Model
{
    protected $fillable = ['entry_date', 'department', 'working', 'total'];

    protected $casts = [
        'entry_date' => 'date',
        'working'    => 'integer',
        'total'      => 'integer',
    ];

    public function label(): string
    {
        return "{$this->working}/{$this->total}";
    }
}
