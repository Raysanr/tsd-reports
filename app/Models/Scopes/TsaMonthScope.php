<?php

namespace App\Models\Scopes;

use App\Support\TsaMonths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/** Shift per month (2026-11-03) — every TsaShift read sees the team / shift
 *  hours / rest day / TikTok flag saved for TsaMonths::contextMonth(), so
 *  every report's existing where('team', …) / ->team / ->tiktok_upsell
 *  follows the month without touching each query. Swaps the FROM for a
 *  same-named subquery over tsa_shifts LEFT JOIN that month's rows; a TSA
 *  with no row for the month keeps her own columns. A no-op until the first
 *  month is added. Writes skip it (TsaShiftBuilder; Model::save() never
 *  applies global scopes). */
class TsaMonthScope implements Scope
{
    private static array $columns = [];

    public function apply(Builder $builder, Model $model)
    {
        $month = TsaMonths::effective(TsaMonths::contextMonth());
        if ($month === null) {
            return;
        }

        $table = $model->getTable();
        $connection = $model->getConnection();
        $columns = self::$columns[$connection->getName() . ':' . $connection->getDatabaseName()] ??= Schema::connection($connection->getName())->getColumnListing($table);

        $select = array_map(function (string $column) use ($connection) {
            $wrapped = $connection->getQueryGrammar()->wrap($column);

            return in_array($column, TsaMonths::FIELDS, true)
                ? "CASE WHEN tsa_month.id IS NULL THEN base.{$wrapped} ELSE tsa_month.{$wrapped} END AS {$wrapped}"
                : "base.{$wrapped}";
        }, $columns);

        $sub = $connection->table($table . ' as base')
            ->leftJoin('tsa_month_settings as tsa_month', fn ($join) => $join->on('tsa_month.tsa_shift_id', '=', 'base.id')->where('tsa_month.month', '=', $month))
            ->selectRaw(implode(', ', $select));

        $builder->getQuery()->fromSub($sub, $table);
    }
}
