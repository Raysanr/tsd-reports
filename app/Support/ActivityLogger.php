<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public static function log(string $action, ?Model $subject, string $description): void
    {
        // Snapshot the acting user's name/email now, not just their id — user_id
        // is nullOnDelete() (see the activity_logs migration) so a log row
        // survives that account being deleted later, but WHO did it would
        // otherwise be lost forever the moment that happens. See the
        // add_actor_snapshot migration for the same backfill on existing rows.
        $actor = auth()->user();

        ActivityLog::create([
            'user_id'      => auth()->id(),
            'actor_name'   => $actor?->name,
            'actor_email'  => $actor?->email,
            'action'       => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id'   => $subject?->getKey(),
            'description'  => $description,
        ]);
    }
}
