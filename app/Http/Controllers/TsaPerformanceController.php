<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TsaPerformanceController extends Controller
{
    /** Full-day window: covers overnight shifts (e.g. Marisol's 10PM–5PM) that start before 8AM. */
    private const START_HOUR = 0;
    private const END_HOUR   = 23;

    /** Accumulator keys for per-row / per-block / grand totals. */
    private const COLUMNS = [
        'total', 'catered',
        'confirmed_via_call', 'upsell_confirmation', 'call_back', 'call_dropped',
        'repeat_order_upsell', 'rude_customer', 'relatives_confirmation',
        'dfr', 'double_order', 'fsd_uncleared', 'not_answering', 'unattended', 'invalid_number',
        'excess',
    ];

    /** Dispositions that count as "touched" for the excess calc — every COLUMNS
     *  entry except 'total' (the whole) and 'excess' itself (the remainder). */
    private const DISPOSITION_KEYS = [
        'confirmed_via_call', 'upsell_confirmation', 'call_back', 'call_dropped',
        'repeat_order_upsell', 'rude_customer', 'relatives_confirmation',
        'dfr', 'double_order', 'fsd_uncleared', 'not_answering', 'unattended', 'invalid_number',
    ];

    /** Display metadata for the disposition columns (excludes 'total', which has its own fixed header). */
    private const METRIC_COLUMNS = [
        ['key' => 'confirmed_via_call',     'label' => 'Confirmed<br>via Call',        'group' => 'answered', 'min_width' => 72],
        ['key' => 'upsell_confirmation',    'label' => 'Upsell w/<br>Confirmation',    'group' => 'answered', 'min_width' => 72, 'highlight' => true],
        ['key' => 'call_back',              'label' => 'Call<br>Back',                 'group' => 'answered', 'min_width' => 72],
        ['key' => 'call_dropped',           'label' => 'Call<br>Dropped',              'group' => 'answered', 'min_width' => 72],
        ['key' => 'repeat_order_upsell',    'label' => 'Repeat Order<br>w/ Upsell',    'group' => 'answered', 'min_width' => 80],
        ['key' => 'rude_customer',          'label' => 'Rude<br>Customer',             'group' => 'answered', 'min_width' => 72],
        ['key' => 'relatives_confirmation', 'label' => 'Relatives<br>Confirmation',    'group' => 'answered', 'min_width' => 80],
        ['key' => 'dfr',                    'label' => 'Duplicate<br>(DFR)',           'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'double_order',           'label' => 'Double Order<br>(System)',     'group' => 'unanswered', 'min_width' => 80],
        ['key' => 'fsd_uncleared',          'label' => 'FSD<br>Uncleared',             'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'not_answering',          'label' => 'Not<br>Answering',             'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'unattended',             'label' => 'Unat-<br>tended',              'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'invalid_number',         'label' => 'Invalid<br>Number',            'group' => 'unanswered', 'min_width' => 72],
        // Leads with no MATCHING disposition tag — covers both untouched leads (no tag
        // yet) and leads the night-shift bulk action later tags "UNCATERED LEADS" at
        // midnight, since that tag doesn't substring-match any of the 13 tracked
        // dispositions above and so still falls through to here. Computed as total
        // minus every tagged disposition, floored at 0 so a TSA over-tagging a lead
        // (e.g. logging it under two dispositions) can't push this negative.
        ['key' => 'excess',                 'label' => 'Excess<br>Leads',              'group' => 'excess', 'min_width' => 80],
    ];

    public function index()
    {
        $selectedDate = request('date', now('Asia/Manila')->format('Y-m-d'));
        $date         = Carbon::parse($selectedDate);
        $showEmpty    = request()->boolean('show_empty');
        $selectedTeam = request('team', 'sh-naturals');
        $teamsConfig  = config('teams', []);

        if (!array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'sh-naturals';
        }

        // Per-product toggle (the sheets split each team's report into one tab per
        // pack/product — this mirrors that). 'all' means no filtering, same as before.
        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        $availableProducts = Product::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->orderBy('sort_order')->get();
        $selectedProduct   = request('product', 'all');

        $selectedProductModel = $availableProducts->firstWhere('display_name', $selectedProduct);
        if ($selectedProduct !== 'all' && !$selectedProductModel) {
            $selectedProduct = 'all';
        }

        // Load the roster for the selected team only
        $shifts = TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->orderBy('sort_order')->get()->keyBy('tsa_key');

        // All orders for the selected date: either assigned to a TSA on this team's
        // roster, OR never claimed by any TSA at all but still attributable to this
        // team by product (Fix #15 in SyncTodayOrders — a lead can have a real product
        // in its cart with no TSA tag at all, e.g. swept by the midnight "UNCATERED
        // LEADS" bulk action before anyone touched it; `whereIn(...)` alone would
        // silently drop these since SQL IN never matches NULL, hiding them from every
        // team's report entirely instead of counting them as that team's Excess).
        // whereBetween (not whereDate) keeps the query sargable against the pancake_created_at index.
        $orders = Order::whereBetween('pancake_created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->where(function ($q) use ($shifts, $teamsConfig, $selectedTeam) {
                $q->whereIn('tsa_name', $shifts->keys())
                  ->orWhere(function ($q2) use ($teamsConfig, $selectedTeam) {
                      $q2->whereNull('tsa_name')->where('team', $teamsConfig[$selectedTeam]['order_team']);
                  });
            })
            ->get();

        if ($selectedProduct !== 'all') {
            $matchKeyword = $selectedProductModel->effective_keyword;
            $orders = $orders->filter(function ($order) use ($matchKeyword) {
                foreach ($order->raw_tags ?? [] as $tag) {
                    if (stripos($tag, $matchKeyword) !== false) return true;
                }
                return false;
            })->values();
        }

        $allKeys        = $shifts->keys();
        $ordersByHour   = $orders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));

        $hourBlocks = [];
        $totals     = array_fill_keys(self::COLUMNS, 0);

        for ($hour = self::START_HOUR; $hour <= self::END_HOUR; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());

            if ($hourOrders->isEmpty() && !$showEmpty) {
                continue;
            }

            // '__unassigned__' sentinel (not literal null) so groupBy's key comparison
            // is unambiguous — bucket for orders with no tsa_name at all.
            $ordersByTsa  = $hourOrders->groupBy(fn($o) => $o->tsa_name ?? '__unassigned__');
            $rows         = [];
            $blockTotals  = array_fill_keys(self::COLUMNS, 0);

            foreach ($allKeys as $key) {
                $shift     = $shifts->get($key);
                $tsaOrders = $ordersByTsa->get($key, collect());
                $row       = $this->buildRow($shift, $key, $tsaOrders);

                foreach (self::COLUMNS as $col) {
                    $blockTotals[$col] += $row[$col];
                    $totals[$col]      += $row[$col];
                }

                $rows[] = $row;
            }

            // Never-claimed-by-any-TSA orders (see the query comment above) — shown as
            // their own row, only when this hour actually has any, so they're visible
            // as this team's Excess instead of being silently absorbed into nothing.
            $unassignedOrders = $ordersByTsa->get('__unassigned__', collect());
            if ($unassignedOrders->isNotEmpty()) {
                $row = $this->buildRow(null, 'unassigned', $unassignedOrders, 'Unassigned');

                foreach (self::COLUMNS as $col) {
                    $blockTotals[$col] += $row[$col];
                    $totals[$col]      += $row[$col];
                }

                $rows[] = $row;
            }

            $hourBlocks[] = [
                'label'            => HourFormatter::rangeLabel($hour),
                'rows'             => $rows,
                'totals'           => $blockTotals,
                'conversion_rate'  => $this->conversionRate($blockTotals),
            ];
        }

        $teams       = collect($teamsConfig)->map(fn($t) => $t['name']);
        $metricCols  = self::METRIC_COLUMNS;
        $totalConversionRate = $this->conversionRate($totals);

        return view('tsa-performance', compact(
            'selectedDate', 'hourBlocks', 'totals', 'showEmpty',
            'teams', 'selectedTeam', 'metricCols', 'totalConversionRate',
            'availableProducts', 'selectedProduct'
        ));
    }

    /** Upsell conversions as a % of total called leads — null when there were no leads at all. */
    private function conversionRate(array $columns): ?float
    {
        if ($columns['total'] <= 0) return null;
        return round($columns['upsell_confirmation'] / $columns['total'] * 100, 1);
    }

    private function buildRow(?TsaShift $shift, ?string $key, Collection $orders, ?string $displayNameOverride = null): array
    {
        $row = [
            'display_name'           => $displayNameOverride ?? $shift?->display_name ?? ucfirst($key),
            'total'                  => $orders->count(),
            'confirmed_via_call'     => $this->count($orders, 'confirmed via call'),
            'upsell_confirmation'    => $orders->where('is_upsell', true)->count(),
            'call_back'              => $this->count($orders, 'call back'),
            'call_dropped'           => $this->count($orders, 'call dropped'),
            'repeat_order_upsell'    => $this->count($orders, 'repeat order'),
            'rude_customer'          => $this->count($orders, 'rude customer'),
            'relatives_confirmation' => $this->count($orders, 'relatives'),
            'dfr'                    => $this->count($orders, 'dfr'),
            'double_order'           => $this->count($orders, 'double order'),
            'fsd_uncleared'          => $this->count($orders, 'fsd'),
            'not_answering'          => $this->count($orders, 'not answering'),
            'unattended'             => $this->count($orders, 'unattended'),
            'invalid_number'         => $this->count($orders, 'invalid number'),
        ];

        $row['catered'] = array_sum(array_intersect_key($row, array_flip(self::DISPOSITION_KEYS)));
        $row['excess']  = max($row['total'] - $row['catered'], 0);

        $row['conversion_rate'] = $this->conversionRate($row);

        return $row;
    }

    private function count(Collection $orders, string $keyword): int
    {
        return $this->countAny($orders, [$keyword]);
    }

    /** True if disposition matches ANY of the given keywords (case-insensitive substring). */
    private function countAny(Collection $orders, array $keywords): int
    {
        // Fix #10: disposition is stored as the raw tag text, which for SH Naturals'
        // "RELATIVE'S CONFIRMATION-<product>" tags includes an apostrophe that would
        // otherwise break this substring match against the apostrophe-free keyword.
        return $orders->filter(function ($o) use ($keywords) {
            $disposition = str_replace("'", '', $o->disposition ?? '');
            foreach ($keywords as $kw) {
                if (stripos($disposition, $kw) !== false) return true;
            }
            return false;
        })->count();
    }
}
