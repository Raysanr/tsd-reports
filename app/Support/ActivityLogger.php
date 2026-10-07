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

    /** Logs ONE field's autosave edit on a Data Management page (explicit
     *  request, 2026-10-07: "all activites in every page should be
     *  recorded ... what she edited") — shared by every Data Management
     *  controller's own debounced-PATCH-per-field endpoints
     *  (Projections/DSPPR/Summary Sales Report/Expected Income/Cost
     *  Breakdown) instead of each one hand-building its own description
     *  string. $action MUST be prefixed with the owning page's own module
     *  slug (e.g. 'cost_breakdown.field_updated', 'dsppr.field_updated')
     *  — DataManagementActivityLogController's own TSA-visibility filter
     *  excludes anything starting 'cost_breakdown.' by that prefix alone,
     *  not a separate module column (no schema change needed — same
     *  "{subject}.{verb}" action-naming convention this app already uses
     *  everywhere else).
     *
     *  $fieldKey is mechanically humanized the same way the Activity Log
     *  page already humanizes an action slug ('net_income_target' ->
     *  "Net income target") rather than requiring a hand-maintained label
     *  map per controller — every field key across all 5 pages already
     *  reads fine under this transform, confirmed by spot-checking each
     *  controller's own validate() whitelist.
     *
     *  $context is a short human phrase identifying WHICH row/card/date
     *  this edit belongs to (e.g. 'SINUXYL on Oct 7, 2026', 'Opening
     *  Shift', 'Mariel Entanto') — omit (null) when the subject itself is
     *  unambiguous without it (e.g. a role's own base_salary).
     *
     *  $oldValue/$newValue are formatted with $formatter if given
     *  (fmtMoney/fmtPct/etc.), otherwise cast to string as-is. A null
     *  $oldValue (field was never set before) reads as "(blank)" rather
     *  than the word "null" leaking into the sentence. */
    public static function logFieldUpdate(
        string $action,
        ?Model $subject,
        string $fieldKey,
        mixed $oldValue,
        mixed $newValue,
        ?string $context = null,
        ?\Closure $formatter = null
    ): void {
        $label = \Illuminate\Support\Str::of($fieldKey)->replace('_', ' ')->ucfirst();
        $fmt = fn ($v) => $v === null ? '(blank)' : ($formatter ? $formatter($v) : (string) $v);

        $description = "Updated {$label}" . ($context ? " for {$context}" : '')
            . " from {$fmt($oldValue)} to {$fmt($newValue)}.";

        self::log($action, $subject, $description);
    }
}
