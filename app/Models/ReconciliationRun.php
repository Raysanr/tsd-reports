<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationRun extends Model
{
    protected $fillable = [
        'ran_at', 'checked_date', 'local_count', 'pancake_count',
        'issues', 'issue_count', 'has_issues',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
        'checked_date' => 'date',
        'issues' => 'array',
        'has_issues' => 'boolean',
    ];
}
