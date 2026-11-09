<?php

namespace App\Support;

/** Which Cost Breakdown a request works on (2026-11-09: "add Actual Cost
 *  Breakdown page ... same as in the cost breakdown page").
 *
 *  'budget' — the original Cost Breakdown page. Everything else in the app
 *  (Expected Income, DSPPR, Summary Sales, TsaDailyRateService) reads this.
 *  'actual' — Actual Cost Breakdown: the same page, tables and formulas on
 *  its own separate rows (cost_breakdown_*.variant), so nothing typed there
 *  ever changes the budget figures the other pages use.
 *
 *  Set once per request by DataManagementDataset (before route-model
 *  binding, so a bound role/pool comes from the right variant) and reset at
 *  the start of every request (ResetDataSet), like DataSet itself. */
class CostBreakdownVariant
{
    public const BUDGET = 'budget';
    public const ACTUAL = 'actual';

    private static string $current = self::BUDGET;

    public static function current(): string
    {
        return self::$current;
    }

    public static function isActual(): bool
    {
        return self::$current === self::ACTUAL;
    }

    public static function set(string $variant): void
    {
        $variant = $variant === self::ACTUAL ? self::ACTUAL : self::BUDGET;
        if ($variant !== self::$current) {
            self::$current = $variant;
            RequestMemo::flush();
        }
    }

    public static function reset(): void
    {
        self::set(self::BUDGET);
    }

    /** Runs $callback on $variant, then switches back. */
    public static function using(string $variant, \Closure $callback): mixed
    {
        $was = self::$current;
        self::set($variant);
        try {
            return $callback();
        } finally {
            self::set($was);
        }
    }

    /** Route name prefix: data.cost-breakdown / data.actual-cost-breakdown. */
    public static function routeName(): string
    {
        return self::isActual() ? 'data.actual-cost-breakdown' : 'data.cost-breakdown';
    }

    /** URL path segment under /data or /partners. */
    public static function path(): string
    {
        return self::isActual() ? 'actual-cost-breakdown' : 'cost-breakdown';
    }

    public static function label(): string
    {
        return self::isActual() ? 'Actual Cost Breakdown' : 'Cost Breakdown';
    }

    /** Activity Log action prefix / session + setting key prefix. */
    public static function slug(): string
    {
        return self::isActual() ? 'actual_cost_breakdown' : 'cost_breakdown';
    }
}
