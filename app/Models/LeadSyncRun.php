<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ported from call-tracker (merged into one app 2026-08-12), renamed from
 *  that app's `SyncRun` — tsd-reports' own SyncRun model (Order sync) has
 *  incompatible columns, so this stays a distinct model for the Lead-sync
 *  job. */
class LeadSyncRun extends Model
{
    protected $fillable = [
        'ran_at', 'total_fetched', 'new_leads', 'skipped',
        'duration_ms', 'success', 'error_message',
    ];

    protected $casts = [
        'ran_at'  => 'datetime',
        'success' => 'boolean',
    ];
}
