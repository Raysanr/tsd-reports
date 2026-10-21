<?php

namespace App\Support;

use App\Models\ProjectionCustomRow;
use App\Models\RowSortOrder;
use App\Models\Setting;

/**
 * Projections rows are PER MONTH (explicit request, 2026-10-21: "i want to
 * make it every month is stay only on that month"). Custom rows (the +
 * rows), their % rates and the row order all belong to one month — adding,
 * deleting, re-rating or dragging a row in November never touches October.
 * Expected Income / Actual Income show the rows of the month being viewed.
 *
 * A month's rows don't exist until it is first used; then they're copied
 * from the nearest earlier month that has rows (or the nearest later one),
 * so a new month starts as a copy of the previous one.
 *
 * The month in use lives on the current request (request attributes), so
 * it can't leak between requests under Octane.
 */
class ProjectionMonth
{
    private const ATTR = 'projection_month';

    public static function current(): string
    {
        $month = request()?->attributes->get(self::ATTR) ?? now()->format('Y-m');
        self::ensure($month);

        return $month;
    }

    public static function set(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return;
        }
        request()?->attributes->set(self::ATTR, $month);
    }

    /** Run $fn with $month as the projection month, then put the old one back. */
    public static function with(string $month, \Closure $fn): mixed
    {
        $attrs = request()?->attributes;
        $previous = $attrs?->get(self::ATTR);
        self::set($month);
        try {
            return $fn();
        } finally {
            $previous === null ? $attrs?->remove(self::ATTR) : $attrs?->set(self::ATTR, $previous);
        }
    }

    public static function rateKey(string $rowKey, ?string $month = null): string
    {
        return 'projection_rate.' . ($month ?? self::current()) . '.' . $rowKey;
    }

    /** Copy the nearest month's rows/order/rates into $month the first time it's used. */
    public static function ensure(string $month): void
    {
        RequestMemo::remember('projection_month.ready.' . $month, function () use ($month) {
            $order = RowSortOrder::withoutGlobalScope('month');
            if ((clone $order)->where('month', $month)->exists()) {
                return true;
            }
            $months = (clone $order)->whereNotNull('month')->distinct()->orderBy('month')->pluck('month');
            $source = $months->filter(fn ($m) => $m < $month)->last() ?? $months->first(fn ($m) => $m > $month);
            if ($source === null) {
                return true; // nothing to copy — RowOrder seeds the built-in rows
            }
            self::copy($source, $month);

            return true;
        });
    }

    public static function copy(?string $from, string $to): void
    {
        $now = now();
        ProjectionCustomRow::withoutGlobalScope('month')->insertOrIgnore(
            ProjectionCustomRow::withoutGlobalScope('month')->where('month', $from)->get()
                ->map(fn ($r) => ['month' => $to, 'key' => $r->key, 'section' => $r->section, 'label' => $r->label, 'is_fixed' => $r->is_fixed, 'sort_order' => $r->sort_order, 'created_at' => $now, 'updated_at' => $now])->all()
        );
        RowSortOrder::withoutGlobalScope('month')->insertOrIgnore(
            RowSortOrder::withoutGlobalScope('month')->where('month', $from)->get()
                ->map(fn ($r) => ['month' => $to, 'row_key' => $r->row_key, 'section' => $r->section, 'sort_order' => $r->sort_order, 'created_at' => $now, 'updated_at' => $now])->all()
        );
        $prefix = $from === null ? 'projection_rate.' : "projection_rate.{$from}.";
        $rates = Setting::where('key', 'like', $prefix . '%')->get()
            ->filter(fn ($s) => $from !== null || substr_count($s->key, '.') === 1); // legacy keys only: projection_rate.{row}
        Setting::insertOrIgnore($rates->map(fn ($s) => [
            'key' => "projection_rate.{$to}." . substr($s->key, strlen($prefix)), 'value' => $s->value, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());
        RequestMemo::flush();
    }
}
