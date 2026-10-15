<?php

namespace App\Models\Scopes;

use App\Models\TsaShift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** TsaShift's soft-delete scope, date-aware (explicit request, 2026-10-15:
 *  "when the tsa remove to the tsa management ... the day it is deleted the
 *  old data is still displaying but onwards it is gone").
 *
 *  Default (no report date set — live pages, Monitor, round-robin, artisan):
 *  exactly Laravel's SoftDeletingScope, a removed TSA is gone.
 *  On a report page that called TsaShift::showDeletedFrom($dateFrom): a TSA
 *  removed ON or AFTER the range's first day is still on the roster, so her
 *  row and her share of every total come back for dates up to the day she
 *  was removed; a range starting after that day doesn't include her.
 *  withTrashed()/onlyTrashed() keep working (same scope class identity). */
class TsaRosterScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model)
    {
        $from = TsaShift::rosterFrom();
        if ($from === null) {
            parent::apply($builder, $model);
            return;
        }

        $column = $model->getQualifiedDeletedAtColumn();
        $builder->where(fn ($q) => $q->whereNull($column)->orWhere($column, '>=', $from . ' 00:00:00'));
    }
}
