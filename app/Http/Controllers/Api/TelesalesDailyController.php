<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DataManagement\TsaSalesReportController;
use App\Models\ExpectedIncomeEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\ActualSplit;
use App\Support\DataSet;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProductPerformance;
use App\Support\Teams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/external/v1/telesales/daily?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Read-only daily Telesales (TSD, SH E-COM teams) figures for other
 * departments, built from the same definitions the app's own pages use:
 * - leads: Leads Report's lead count (Deleted / excluded sellers out,
 *   hidden products out), by COALESCE(inserted, created) date.
 * - orders / grossSales / cancelledSales / deliveredSales / returnedSales:
 *   ActualSplit (RTS Report, Actual Income) — real upsells, by order date,
 *   split by each order's CURRENT Pancake status.
 * - catered: Leads Report's Catered Leads (leads with a call result).
 * - leadsFromFsd: every Telesales lead is a Facebook-page Pancake order, so
 *   it equals leads.
 * - cancelledOrders: count behind cancelledSales (Cancelled + Restocking).
 * - separateParcelOrders: real upsells shipped as their own parcel
 *   (SEPARATE PARCEL tag or note); a normal upsell rides in the FB parcel.
 * - productCost / shippingFee: Expected Income's manual Product Cost and
 *   Shipping Fee entries for that day (every product, every TSA plus the
 *   product-level row); null when nothing was entered that day.
 * - agents: the same per TSA.
 * - partnership: TSD's partner companies (M&J, PEAK.CO, MLC), exactly as
 *   TSD Overall Performance's Sales Summary shows them; TSD is credited
 *   shareRate (70%) of each partner's income before OPEX.
 */
class TelesalesDailyController extends Controller
{
    private const MAX_DAYS = 93;

    /** Building a range takes seconds (the partner figures especially), so a
     *  result is reused for this long. Figures can be up to 10 minutes old. */
    private const CACHE_SECONDS = 600;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return response()->json(['error' => 'Range too long (max ' . self::MAX_DAYS . ' days)'], 422);
        }

        $key = 'telesales-api:v2:' . $data['from'] . ':' . $data['to'];
        $body = Cache::get($key);
        if ($body === null) {
            $body = $this->build($data['from'], $data['to'], $from, $to);
            // Don't keep a result whose partner data failed to load.
            if (! collect($body['days'])->contains(fn ($day) => $day['partnership'] === null)) {
                Cache::put($key, $body, self::CACHE_SECONDS);
            }
        }

        return response()->json($body);
    }

    private function build(string $fromDate, string $toDate, Carbon $from, Carbon $to): array
    {
        $data = ['from' => $fromDate, 'to' => $toDate];
        $orderTeams = collect(Teams::config())->pluck('order_team')->all();
        TsaShift::showDeletedFrom($data['from']);
        $tsas = TsaShift::whereIn('team', $orderTeams)->get()->keyBy('tsa_key');

        $orders = ProductPerformance::withoutHiddenProductOrders(Order::whereIn('team', $orderTeams)
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->get())
            ->groupBy(fn (Order $o) => $o->effective_created_at->format('Y-m-d'));

        $partnership = self::partnershipByDate($data['from'], $data['to']);
        $costs = self::expectedIncomeCosts($data['from'], $data['to']);

        $days = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $dayOrders = $orders->get($key, collect());
            $split = ActualSplit::fromOrders($dayOrders);

            $leads = self::leads($dayOrders);
            $dayCosts = $costs->get($key, collect());
            $days[] = [
                'date'           => $key,
                'leads'          => $leads['total'],
                'leadsFromFsd'   => $leads['total'],
                'catered'        => $leads['catered'],
                'orders'         => ActualSplit::realUpsells($dayOrders)->count(),
                'cancelledOrders' => ActualSplit::ordersInBucket($dayOrders, 'cancelled')->count(),
                'separateParcelOrders' => ActualSplit::realUpsells($dayOrders)->filter(fn (Order $o) => Order::isSeparateParcel($o))->count(),
                'grossSales'     => round($split['gross_sales'], 2),
                'cancelledSales' => round($split['cancelled'], 2),
                'deliveredSales' => round($split['delivered'], 2),
                'returnedSales'  => round($split['returns'], 2),
                'byStatus'       => self::byStatus($dayOrders),
                'upsellSales'    => round($split['gross_sales'], 2),
                'productCost'    => self::costSum($dayCosts, 'product_cost'),
                'shippingFee'    => self::costSum($dayCosts, 'shipping_fee'),
                'agents'         => self::agents($dayOrders, $tsas, $dayCosts),
                'partnership'    => $partnership === null ? null : self::partnershipDay($partnership, $key),
            ];
        }

        return [
            'department'  => 'Telesales',
            'from'        => $data['from'],
            'to'          => $data['to'],
            'timezone'    => config('app.timezone'),
            'currency'    => 'PHP',
            'generatedAt' => now()->toIso8601String(),
            'days'        => $days,
        ];
    }

    /** Partners' per-team daily figures from the partners data set (same
     *  call as Sales Summary), or null if that data can't be loaded. */
    private static function partnershipByDate(string $from, string $to): ?array
    {
        try {
            return DataSet::onPartners(fn () => [
                'teams'     => app(TsaSalesReportController::class)->overallByDate($from, $to)['teams'] ?? [],
                'shareRate' => ExpectedIncomeCalculator::partnershipShareRate() ?? 0.7,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function partnershipDay(array $partnership, string $date): array
    {
        $partners = [];
        foreach (array_column(DataSet::partnerTeams(), 'name') as $name) {
            $day = $partnership['teams'][$name][$date] ?? [];
            $partners[] = [
                'partner'          => $name,
                'leads'            => (int) ($day['total_leads'] ?? 0),
                'catered'          => (int) ($day['catered_leads'] ?? 0),
                'orders'           => (int) ($day['total_orders'] ?? 0),
                'grossSales'       => round((float) ($day['gross_sales'] ?? 0), 2),
                'ibOpex'           => round((float) ($day['income_before_opex'] ?? 0), 2),
                'partnershipSales' => round((float) ($day['shared_income'] ?? 0), 2),
            ];
        }
        $sum = fn (string $field) => array_sum(array_column($partners, $field));

        return [
            'shareRate'          => (float) $partnership['shareRate'],
            'leads'              => $sum('leads'),
            'catered'            => $sum('catered'),
            'partnershipOrders'  => $sum('orders'),
            'grossSales'         => round($sum('grossSales'), 2),
            'ibOpex'             => round($sum('ibOpex'), 2),
            'partnershipSales'   => round($sum('partnershipSales'), 2),
            'partners'           => $partners,
        ];
    }

    /** Expected Income entries (visible products) in range, grouped by day. */
    private static function expectedIncomeCosts(string $from, string $to): Collection
    {
        return ExpectedIncomeEntry::whereIn('product_id', Product::where('is_hidden', false)->pluck('id'))
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get(['entry_date', 'tsa_id', 'product_cost', 'shipping_fee'])
            ->groupBy(fn (ExpectedIncomeEntry $e) => $e->entry_date->toDateString());
    }

    /** Sum of one entered cost, or null when no entry has it (not entered yet). */
    private static function costSum(Collection $entries, string $field): ?float
    {
        $entered = $entries->filter(fn (ExpectedIncomeEntry $e) => (float) $e->{$field} != 0.0);

        return $entered->isEmpty() ? null : round((float) $entered->sum($field), 2);
    }

    /** Leads Report's Total Leads and Catered Leads for these orders. */
    private static function leads(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return ['total' => 0, 'catered' => 0];
        }
        $tally = ProductPerformance::tally($orders);

        return ['total' => $tally['total'], 'catered' => $tally['catered']];
    }

    /** {status, orders, sales} for every status the day's real upsells are in. */
    private static function byStatus(Collection $orders): array
    {
        return ActualSplit::realUpsells($orders)
            ->groupBy('status_code')
            ->map(fn (Collection $group, $code) => [
                'status' => Order::STATUS_LABELS[$code] ?? (string) $code,
                'orders' => $group->count(),
                'sales'  => round((float) $group->sum(fn (Order $o) => $o->realUpsellAmount()), 2),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    /** Per TSA: leads, catered, orders, cancelledOrders, grossSales, productCost (her own entries only), byStatus. Orders with no TSA are left out. */
    private static function agents(Collection $orders, Collection $tsas, Collection $dayCosts): array
    {
        return $orders->filter(fn (Order $o) => filled($o->tsa_name))
            ->groupBy('tsa_name')
            ->map(function (Collection $group, string $tsaKey) use ($tsas, $dayCosts) {
                $split = ActualSplit::fromOrders($group);
                $leads = self::leads($group);
                $tsaId = $tsas->get($tsaKey)?->id;

                return [
                    'tsa'        => $tsaKey,
                    'name'       => $tsas->get($tsaKey)?->display_name ?? $tsaKey,
                    'team'       => $tsas->get($tsaKey)?->team,
                    'leads'      => $leads['total'],
                    'catered'    => $leads['catered'],
                    'orders'     => ActualSplit::realUpsells($group)->count(),
                    'cancelledOrders' => ActualSplit::ordersInBucket($group, 'cancelled')->count(),
                    'grossSales' => round($split['gross_sales'], 2),
                    'productCost' => $tsaId === null ? null : self::costSum($dayCosts->where('tsa_id', $tsaId), 'product_cost'),
                    'byStatus'   => self::byStatus($group),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
