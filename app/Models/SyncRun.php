<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = [
        'ran_at', 'total_synced', 'new_orders', 'upsell_count',
        'upsell_sales', 'duration_ms', 'success', 'error_message',
    ];

    protected $casts = [
        'ran_at'       => 'datetime',
        'upsell_sales' => 'decimal:2',
        'success'      => 'boolean',
    ];
}
