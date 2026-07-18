<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public static function log(string $action, ?Model $subject, string $description): void
    {
        ActivityLog::create([
            'user_id'      => auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id'   => $subject?->getKey(),
            'description'  => $description,
        ]);
    }
}
