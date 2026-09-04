<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamNameHistory extends Model
{
    protected $table = 'team_name_history';

    protected $fillable = ['slug', 'name', 'effective_from'];

    protected $casts = [
        'effective_from' => 'date',
    ];
}
