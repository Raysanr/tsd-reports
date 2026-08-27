<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Explicit request, 2026-08-26: "smart reports... cards with messages" —
 * plain-language flags a supervisor would otherwise have to dig through
 * TSA Performance/Analytics/Leads by hand to notice. Every number quoted in
 * a card's message is computed with the SAME formulas those existing pages
 * already use (ProductPerformance::tally()/rates(), RtsReportController's
 * own is_returned_upsell/is_upsell+status split) — deliberately no new/
 * parallel definition of Catered, Excess, Conversion Rate, etc. Explicit
 * priority from that same request: "i want to make it all accurate," so
 * this only ever surfaces a card when there's ENOUGH volume to trust the
 * number behind it (see MIN_ANSWERED_FOR_RATE below) — a quiet day produces
 * fewer cards, not misleading ones.
 *
 * Scope, explicit request 2026-08-27: TSD Reports proper only — Analytics/
 * Charts, TSA Performance, Leads Report, RTS Report — never Call Tracker's
 * own Lead-assignment data (overdue-call backlog, daily cap/capacity). This
 * class only ever touches Order/TsaShift(display_name)/ProductPerformance,
 * never App\Models\Lead or anything CallTracker-namespaced.
 *
 * Action Plan, explicit request 2026-08-27: "action plan is smart report
 * too based of the accurate data." Every card here also carries an 'action'
 * — a concrete next step derived from the SAME numbers as its 'message',
 * computed inline at the same call site (never parsed back out of the
 * message string afterward). insights.blade.php's Action Plan view is just
 * a filter over cards that have one, sorted the same severity-first way.
 */
class InsightsGenerator
{
    /** How many days back this whole page looks — matches Analytics' own
     *  default window (Aug 13–26 in the reference screenshot = 14 days).
     *  Also doubles as the week-over-week window: exactly two 7-day weeks. */
    private const LOOKBACK_DAYS = 14;

    /** A day's conversion rate isn't trusted for trend/baseline comparison
     *  below this many answered calls — a rate computed off 1-2 calls swings
     *  wildly and would flag noise, not a real trend. Mirrors rates()'s own
     *  null-when-denominator-is-0 caution, just with a higher bar. */
    private const MIN_ANSWERED_FOR_RATE = 3;

    /** Percentage-point gap from a TSA's own trailing baseline before their
     *  selected day's conversion rate is worth a card (either direction). */
    private const TREND_DELTA_POINTS = 15.0;

    /** A day's Excess must be at least this many multiples of the OTHER
     *  days' average, AND at least MIN_EXCESS_SPIKE_COUNT leads, before it's
     *  flagged as a spike rather than normal day-to-day variance. */
    private const EXCESS_SPIKE_MULTIPLIER = 2.0;
    private const MIN_EXCESS_SPIKE_COUNT = 10;

    /** RTS rate (returned ÷ (returned + delivered)) this many percentage
     *  points above the TEAM's own average — not a hardcoded absolute
     *  percent, which would need knowing this business's real baseline RTS
     *  rate to calibrate correctly. A relative gap against peers on the
     *  same products/customers/season is defensible without that; an
     *  invented absolute cutoff isn't. Requires MIN_RTS_SAMPLE_PESOS of
     *  combined upsell activity to trust the rate at all. */
    private const RTS_RATE_GAP_POINTS = 10.0;
    private const MIN_RTS_SAMPLE_PESOS = 500.0;

    /** Day-over-day / week-over-week gates. The conversion-rate deltas are
     *  smaller than TREND_DELTA_POINTS (15pts, per-TSA) on purpose — these
     *  compare the WHOLE shop's rate, which naturally moves less than any
     *  single TSA's day to day, so a smaller shop-wide swing already means
     *  something. MIN_DAY_VOLUME/MIN_WEEK_VOLUME gate New Leads count, same
     *  "don't trust a tiny sample" reasoning as MIN_ANSWERED_FOR_RATE. */
    private const DAY_CONVERSION_DELTA_POINTS = 8.0;
    private const WEEK_CONVERSION_DELTA_POINTS = 6.0;
    private const VOLUME_DELTA_PERCENT = 25.0;
    private const MIN_DAY_VOLUME = 10;
    private const MIN_WEEK_VOLUME = 40;

    /** Explicit request, 2026-08-27: per-TSA daily targets — "Target Metrics
     *  ng isang TSA." Upselling Rate/Pick-up Rate reuse ProductPerformance::
     *  rates()'s own formulas (Upsell ÷ (Upsell + CVC), Answered ÷ Total
     *  Called); AOV reuses TsaPerformanceController::buildRow()'s own
     *  definition (upsell_sales ÷ upsell_confirmation, "average per upsell
     *  order"); Catered Leads is the 'catered' column; Qty Orders is
     *  'upsell_confirmation' itself — the count AOV is already averaged
     *  over, so the two read as one coherent pair (this is the one target
     *  not already a named column elsewhere, so it's the one most likely to
     *  need correcting if "Qty Orders" meant something else). */
    private const TARGET_UPSELLING_RATE = 60.0;
    private const TARGET_PICK_UP_RATE = 60.0;
    private const TARGET_AOV = 800.0;
    private const TARGET_CATERED_LEADS = 75;
    private const TARGET_QTY_ORDERS = 23;

    /** A TSA with almost no volume that day (not yet started their shift,
     *  on leave, etc.) would trivially "miss" every target — not a real
     *  signal. Same noise-gate reasoning as MIN_DAY_VOLUME, just scoped to
     *  one TSA instead of the whole shop. */
    private const MIN_CATERED_FOR_TARGET_CHECK = 20;

    /** Real EOD reports supplied 2026-08-27 compare Gross Sales/Orders/
     *  Pick-up/Upselling/AOV day-over-day as routine reporting, not just an
     *  anomaly alert — dailyRecapCard() below always shows all of them
     *  (given the volume gate), unlike the threshold-gated cards elsewhere
     *  in this file. This constant only decides AOV's line's up/down/flat
     *  wording, not whether the line appears at all. */
    private const AOV_DELTA_PERCENT = 15.0;

    /** A TSA's cancelled upsells are only worth a card when there's a real
     *  pattern: at least MIN_CANCELLED_UPSELLS in the day AND at least
     *  CANCELLED_SHARE_THRESHOLD of their GROSS upsells (confirmed +
     *  cancelled) — explicit request, 2026-08-27, from a real EOD report:
     *  "24 upsells ... naging 19 final upsells dahil sa cancel upsell." */
    private const MIN_CANCELLED_UPSELLS = 3;
    private const CANCELLED_SHARE_THRESHOLD = 0.15;

    /** A "bottom performer" card only fires when the worst rate is
     *  genuinely low in absolute terms, not just "worst of a strong field" —
     *  comparing everyone to a floor instead of only to each other. */
    private const BOTTOM_PERFORMER_MAX_RATE = 40.0;

    /** A product needs at least this many leads that day before "zero
     *  upsells" means anything — a product that barely got any leads at all
     *  isn't a missed-sales signal, just a quiet day for it. */
    private const MIN_LEADS_FOR_ZERO_SALES_CHECK = 5;

    /** @param Carbon|null $referenceDate The day this page is "as of" — the
     *  Insights topbar's date picker (explicit request, 2026-08-27:
     *  "supervisors report today but yesterday data") lets a supervisor view
     *  any past day's cards, not just today's. Defaults to today when a
     *  caller (or a test) doesn't pass one. Every "today"/"yesterday"/"this
     *  week" in this class means relative to THIS date, not the real
     *  wall-clock today.
     * @param string|null $team A config('teams') key (e.g. 'sh-naturals') to
     *  scope every card to that one team, or null for every team combined —
     *  same 'all' vs. specific-team shape as the Dashboard's own team
     *  filter. An unrecognized key is treated the same as null (the
     *  controller already guards against this, same as Dashboard's own
     *  $selectedTeam fallback-to-'all'). */
    public function generate(?Carbon $referenceDate = null, ?string $team = null): Collection
    {
        $referenceDate = ($referenceDate ?? today())->copy()->startOfDay();

        $teamsConfig = config('teams', []);
        $selectedTeams = ($team && array_key_exists($team, $teamsConfig))
            ? [$team => $teamsConfig[$team]]
            : $teamsConfig;
        $orderTeams = array_column($selectedTeams, 'order_team');

        $rangeFrom = $referenceDate->copy()->subDays(self::LOOKBACK_DAYS - 1)->startOfDay();
        $rangeTo   = $referenceDate->copy()->endOfDay();

        // Two separate fetches, deliberately — NOT the same convention.
        // $attributionOrders answers "which day/TSA gets credit for this
        // order," the exact COALESCE(pancake_inserted_at, pancake_created_at)
        // expression TsaPerformanceController::index() uses ("accurate from
        // POS," 2026-08-11) — needed for per-TSA trend/RTS cards below.
        // $activityOrders answers "when did this lead actually arrive/get
        // worked," the plain pancake_created_at ChartsController's own
        // hourly/daily trends already use — needed for the timing cards.
        // Using the wrong one for either would silently disagree with the
        // page a supervisor would go double-check the card against.
        // whereIn('team', $orderTeams) here (not a filter after ->get()) is
        // what scopes EVERY card below to the selected team — the day/week/
        // timing cards never touch config('teams') themselves, so this is
        // the only place team-scoping happens for them.
        $attributionOrders = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [$rangeFrom, $rangeTo]
        )->whereIn('team', $orderTeams)->get();
        $activityOrders = Order::whereBetween('pancake_created_at', [$rangeFrom, $rangeTo])
            ->whereIn('team', $orderTeams)->get();

        $cards = collect();

        foreach ($selectedTeams as $teamConfig) {
            $orderTeam = $teamConfig['order_team'];
            $shifts = TsaShift::where('team', $orderTeam)->orderBy('sort_order')->get();
            $teamOrders = $attributionOrders->where('team', $orderTeam);

            $cards = $cards->merge($this->tsaTrendCards($shifts, $teamOrders, $referenceDate));
            $cards = $cards->merge($this->rtsRateCards($shifts, $teamOrders));
            $cards = $cards->merge($this->targetMetricsCards($shifts, $teamOrders, $referenceDate, $orderTeam));
            $cards = $cards->merge($this->cancelledUpsellCards($shifts, $teamOrders, $referenceDate));
            $cards = $cards->merge($this->zeroSalesProductCards($orderTeam, $teamOrders, $referenceDate));
        }

        if ($card = $this->topPerformerCard($attributionOrders, $referenceDate)) {
            $cards->push($card);
        }
        if ($card = $this->bottomPerformerCard($attributionOrders, $referenceDate)) {
            $cards->push($card);
        }
        if ($card = $this->peakExcessHourCard($activityOrders)) {
            $cards->push($card);
        }
        if ($card = $this->excessSpikeDayCard($activityOrders)) {
            $cards->push($card);
        }

        $cards = $cards->merge($this->dayOverDayCards($attributionOrders, $referenceDate));
        $cards = $cards->merge($this->weekOverWeekCards($attributionOrders, $referenceDate));
        if ($card = $this->dailyRecapCard($attributionOrders, $referenceDate)) {
            $cards->push($card);
        }

        // Computed LAST, from the cards already built above — the
        // narrative's "how many TSAs missed a target" / "is there a
        // cancellations issue" signals read directly off $cards (by
        // category, never by parsing another card's message text).
        if ($card = $this->dailyNarrativeCard($attributionOrders, $referenceDate, $cards, $team)) {
            $cards->push($card);
        }

        // Critical first, then warning, then positive/info — a supervisor
        // scanning top-to-bottom sees what needs attention before what's
        // going well.
        $order = ['critical' => 0, 'warning' => 1, 'info' => 2, 'positive' => 3];
        return $cards->sortBy(fn ($c) => $order[$c['severity']] ?? 4)->values();
    }

    /** Per-TSA: the selected day's conversion rate vs. their own trailing
     *  average over the rest of the lookback window — flags a real drop OR
     *  a real jump, not just "low" (a TSA who's always around 40% isn't
     *  declining by being at 40%). */
    private function tsaTrendCards(Collection $shifts, Collection $teamOrders, Carbon $referenceDate): Collection
    {
        $cards = collect();
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $ordersByTsa = $teamOrders->groupBy(fn ($o) => $o->tsa_name ?? '__unassigned__');
        $refKey = $referenceDate->toDateString();
        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');

        foreach ($shifts as $shift) {
            $tsaOrders = $ordersByTsa->get($shift->tsa_key, collect());
            if ($tsaOrders->isEmpty()) {
                continue;
            }

            $byDate = $tsaOrders->groupBy($attributionDate);

            $refRow = ProductPerformance::tally($byDate->get($refKey, collect()));
            if ($refRow['answered'] < self::MIN_ANSWERED_FOR_RATE || $refRow['conversion_rate'] === null) {
                continue;
            }

            $baselineRates = [];
            foreach ($byDate as $date => $dayOrders) {
                if ($date === $refKey) {
                    continue;
                }
                $row = ProductPerformance::tally($dayOrders);
                if ($row['answered'] >= self::MIN_ANSWERED_FOR_RATE && $row['conversion_rate'] !== null) {
                    $baselineRates[] = $row['conversion_rate'];
                }
            }
            // Fewer than 3 comparable days isn't enough history to call
            // this day a trend either way — could just be a new TSA (see
            // "still ramping up" framing we deliberately don't attempt here).
            if (count($baselineRates) < 3) {
                continue;
            }

            $baseline = array_sum($baselineRates) / count($baselineRates);
            $refRate = $refRow['conversion_rate'];
            $delta = $refRate - $baseline;

            if ($delta <= -self::TREND_DELTA_POINTS) {
                $cards->push($this->card(
                    'warning', 'TSA performance', '📉',
                    "{$shift->display_name}'s conversion rate is {$refRate}% {$dayWord}, well below their own {$this->fmt($baseline)}% average over the last " . self::LOOKBACK_DAYS . ' days — worth a check-in.',
                    "Check in with {$shift->display_name} — find out what's behind the drop (personal issue, technical problem, or a lead-quality issue) before it continues."
                ));
            } elseif ($delta >= self::TREND_DELTA_POINTS) {
                $cards->push($this->card(
                    'positive', 'TSA performance', '📈',
                    "{$shift->display_name}'s conversion rate is {$refRate}% {$dayWord}, well above their own {$this->fmt($baseline)}% average.",
                    "Ask {$shift->display_name} what worked {$dayWord} — worth understanding so it can be repeated by others."
                ));
            }
        }

        return $cards;
    }

    /** Per-TSA: which of the 5 daily targets (Upselling Rate, Pick-up Rate,
     *  AOV, Catered Leads, Qty Orders) the selected day's numbers fell short
     *  of — explicit request, 2026-08-27: "anong reason why they did not
     *  achieve the target metrics." One card per TSA who missed at least
     *  one, naming every metric they missed and by how much, so a
     *  supervisor sees the specific shortfall instead of just a pass/fail.
     *  $orderTeam feeds dominantUnansweredProduct() — a real EOD report
     *  supplied 2026-08-27 tied a TSA's low Pick-up Rate to one specific
     *  product's leads ("majority ng unanswered leads ay galing sa TO
     *  leads"), not just a disposition category. */
    private function targetMetricsCards(Collection $shifts, Collection $teamOrders, Carbon $referenceDate, string $orderTeam): Collection
    {
        $cards = collect();
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $ordersByTsa = $teamOrders->groupBy(fn ($o) => $o->tsa_name ?? '__unassigned__');
        $refKey = $referenceDate->toDateString();
        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');

        foreach ($shifts as $shift) {
            $tsaOrders = $ordersByTsa->get($shift->tsa_key, collect());
            $dayOrders = $tsaOrders->filter(fn ($o) => $attributionDate($o) === $refKey);

            $row = ProductPerformance::tally($dayOrders);
            if ($row['catered'] < self::MIN_CATERED_FOR_TARGET_CHECK) {
                continue;
            }

            $aov = $row['upsell_confirmation'] > 0 ? $row['upsell_sales'] / $row['upsell_confirmation'] : 0.0;

            $misses = [];
            if ($row['upselling_rate'] !== null && $row['upselling_rate'] < self::TARGET_UPSELLING_RATE) {
                $misses[] = 'Upselling Rate ' . $this->fmt($row['upselling_rate']) . '% (target ' . $this->fmt(self::TARGET_UPSELLING_RATE) . '%)';
            }
            if ($row['pick_up_rate'] !== null && $row['pick_up_rate'] < self::TARGET_PICK_UP_RATE) {
                $reasonParts = array_filter([
                    $this->dominantUnansweredReason($row),
                    $this->dominantUnansweredProduct($dayOrders, $orderTeam, $row['unanswered']),
                ]);
                $reason = implode(', ', $reasonParts);
                $misses[] = 'Pick-up Rate ' . $this->fmt($row['pick_up_rate']) . '% (target ' . $this->fmt(self::TARGET_PICK_UP_RATE) . '%)' . ($reason !== '' ? " — {$reason}" : '');
            }
            if ($row['upsell_confirmation'] > 0 && $aov < self::TARGET_AOV) {
                $misses[] = 'AOV ₱' . number_format($aov, 0) . ' (target ₱' . number_format(self::TARGET_AOV, 0) . ')';
            }
            if ($row['catered'] < self::TARGET_CATERED_LEADS) {
                $misses[] = "Catered Leads {$row['catered']} (target " . self::TARGET_CATERED_LEADS . ')';
            }
            if ($row['upsell_confirmation'] < self::TARGET_QTY_ORDERS) {
                $misses[] = "Qty Orders {$row['upsell_confirmation']} (target " . self::TARGET_QTY_ORDERS . ')';
            }

            if (empty($misses)) {
                continue;
            }

            $cards->push($this->card(
                'warning', 'Target metrics', '🎯',
                "{$shift->display_name} is short on " . count($misses) . ' of 5 daily targets ' . $dayWord . ': ' . implode(', ', $misses) . '.',
                "Schedule a coaching check-in with {$shift->display_name} focused on: " . implode(', ', $misses) . '.'
            ));
        }

        return $cards;
    }

    /** Which unanswered disposition (Not Answering, Invalid Number,
     *  Unattended, Duplicate/DFR, Double Order, FSD Uncleared) a low
     *  Pick-up Rate is actually concentrated in — explicit request,
     *  2026-08-27: "there is many leads that is unanswered that's why that
     *  got down the pick up rate" — the reason BEHIND the number, not just
     *  the number itself. Pick-up Rate's own formula is Answered ÷ Total
     *  Called (ProductPerformance::rates()); this just breaks its
     *  denominator's "not answered" half down into the 6 columns
     *  ProductPerformance::tally() already computes. Only names one when it
     *  genuinely dominates (40%+ of the unanswered total) — otherwise the
     *  shortfall is spread across several causes and naming just the
     *  largest would overstate it. */
    private function dominantUnansweredReason(array $row): ?string
    {
        if ($row['unanswered'] <= 0) {
            return null;
        }

        $breakdown = [
            'Not Answering'   => $row['not_answering'],
            'Invalid Number'  => $row['invalid_number'],
            'Unattended'      => $row['unattended'],
            'Duplicate (DFR)' => $row['dfr'],
            'Double Order'    => $row['double_order'],
            'FSD Uncleared'   => $row['fsd_uncleared'],
        ];
        arsort($breakdown);
        $topLabel = array_key_first($breakdown);
        $topCount = $breakdown[$topLabel];

        if ($topCount <= 0 || $topCount < 0.4 * $row['unanswered']) {
            return null;
        }

        return "driven by {$topLabel} ({$topCount} of {$row['unanswered']} unanswered)";
    }

    /** Which PRODUCT a TSA's unanswered leads are actually concentrated in —
     *  explicit request, 2026-08-27, from a real EOD report: "Sa side naman
     *  ni Katherine ... majority ng unanswered leads ay galing sa TO leads"
     *  (majority of her unanswered leads came from the TO product). Reuses
     *  ProductPerformance::buildRow()'s own product-matching (raw_tags/
     *  pancake_product_ids, the exact matching every product table already
     *  uses) rather than a separate keyword check — never a new/parallel
     *  definition of "this order belongs to product X." Only names one
     *  product when it accounts for 40%+ of the TSA's unanswered leads that
     *  day, same dominance bar as dominantUnansweredReason() above. */
    private function dominantUnansweredProduct(Collection $dayOrders, string $orderTeam, int $totalUnanswered): ?string
    {
        if ($totalUnanswered <= 0 || $dayOrders->isEmpty()) {
            return null;
        }

        $teamProducts = Product::where('team', $orderTeam)->orderBy('sort_order')->get();

        $best = null;
        $bestCount = 0;
        foreach ($teamProducts as $product) {
            $unanswered = ProductPerformance::buildRow($product, $dayOrders, $teamProducts)['unanswered'];
            if ($unanswered > $bestCount) {
                $bestCount = $unanswered;
                $best = $product;
            }
        }

        if (!$best || $bestCount < 0.4 * $totalUnanswered) {
            return null;
        }

        return "mostly {$best->display_name} orders";
    }

    /** Every TSA on every team with a trustworthy sample on the selected
     *  day, sorted BEST rate first — shared by topPerformerCard(),
     *  bottomPerformerCard(), and dailyNarrativeCard() below, so "who's
     *  best/worst today" is computed exactly once instead of three times
     *  with three chances to drift apart. */
    private function rankedConversionCandidates(Collection $attributionOrders, Carbon $referenceDate): array
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $refOrders = $attributionOrders->filter(fn ($o) => $attributionDate($o) === $refKey);
        if ($refOrders->isEmpty()) {
            return [];
        }

        $byTsa = $refOrders->groupBy(fn ($o) => $o->tsa_name ?? '__unassigned__');
        $shifts = TsaShift::whereIn('tsa_key', $byTsa->keys())->get()->keyBy('tsa_key');

        // Collect every TSA with a trustworthy sample first — "top/bottom
        // performer" is a comparison, and comparing one TSA to nobody (or
        // crowning/blaming a rate just because it's the only candidate)
        // isn't a real comparison.
        $candidates = [];
        foreach ($byTsa as $tsaKey => $orders) {
            $shift = $shifts->get($tsaKey);
            if (!$shift) {
                continue;
            }
            $row = ProductPerformance::tally($orders);
            if ($row['answered'] < self::MIN_ANSWERED_FOR_RATE || $row['conversion_rate'] === null) {
                continue;
            }
            $candidates[] = ['name' => $shift->display_name, 'rate' => $row['conversion_rate'], 'answered' => $row['answered'], 'upsells' => $row['upsell_confirmation']];
        }

        usort($candidates, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $candidates;
    }

    /** The selected day's single best conversion rate across every TSA on
     *  every team, among those with enough answered calls to trust the
     *  number. */
    private function topPerformerCard(Collection $attributionOrders, Carbon $referenceDate): ?array
    {
        $candidates = $this->rankedConversionCandidates($attributionOrders, $referenceDate);
        if (count($candidates) < 2) {
            return null;
        }

        $best = $candidates[0];
        // Also require a genuinely positive rate — a "best of the day" at
        // 0% (everyone had a rough day) isn't worth calling a top
        // performer.
        if ($best['rate'] <= 0) {
            return null;
        }

        $label = $referenceDate->isToday() ? "today's" : $referenceDate->format('M j') . "'s";
        return $this->card(
            'positive', 'TSA performance', '🏆',
            "{$best['name']} is {$label} top performer: {$best['rate']}% conversion rate, {$best['upsells']} upsells confirmed.",
            "Recognize {$best['name']} for {$label} results — consider sharing their approach with the team."
        );
    }

    /** The selected day's single worst conversion rate across every TSA on
     *  every team — explicit request, 2026-08-27, from a real EOD report's
     *  own narrative about a specific underperforming TSA. Mirrors
     *  topPerformerCard()'s exact comparison logic, just inverted, plus one
     *  extra gate: the worst rate must be genuinely low in absolute terms
     *  (below BOTTOM_PERFORMER_MAX_RATE), not just "worst of a strong
     *  field" — comparing everyone only to each other would flag someone at
     *  70% just because a peer hit 95%. */
    private function bottomPerformerCard(Collection $attributionOrders, Carbon $referenceDate): ?array
    {
        $candidates = $this->rankedConversionCandidates($attributionOrders, $referenceDate);
        if (count($candidates) < 2) {
            return null;
        }

        $worst = $candidates[count($candidates) - 1];
        if ($worst['rate'] > self::BOTTOM_PERFORMER_MAX_RATE) {
            return null;
        }

        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');
        return $this->card(
            'warning', 'TSA performance', '🔻',
            "{$worst['name']}'s conversion rate is {$worst['rate']}% {$dayWord}, the lowest among comparable TSAs ({$worst['answered']} answered calls).",
            "Coach {$worst['name']} on call handling and conversation-to-conversion technique — focus on turning more answered calls into confirmed upsells."
        );
    }

    /** Which hour-of-day generates the most Excess, summed across every day
     *  in the lookback window — same hourly bucketing ChartsController's own
     *  "Excess Leads Trend" chart uses, just re-summed by hour instead of by
     *  day. Also names WHICH shift that Excess mostly comes from — explicit
     *  request, 2026-08-27: "anong oras tumataas ang excess leads? saang
     *  shift ito nanggagaling?" (what hour does Excess rise, which shift
     *  does it come from) — grouping that peak hour's own orders by TSA
     *  answers the second question with the same tally()['excess'] formula,
     *  just re-summed by TSA instead of by hour. */
    private function peakExcessHourCard(Collection $activityOrders): ?array
    {
        if ($activityOrders->isEmpty()) {
            return null;
        }

        $byHour = $activityOrders->groupBy(fn (Order $o) => (int) $o->pancake_created_at->format('G'));
        $excessByHour = $byHour->map(fn ($orders) => ProductPerformance::tally($orders)['excess']);
        if ($excessByHour->sum() <= 0) {
            return null;
        }

        $peakHour = (int) $excessByHour->sortDesc()->keys()->first();
        $peakExcess = $excessByHour[$peakHour];

        $excessByTsa = $byHour[$peakHour]
            ->groupBy(fn (Order $o) => $o->tsa_name ?? '__unassigned__')
            ->map(fn ($orders) => ProductPerformance::tally($orders)['excess'])
            ->filter(fn ($e) => $e > 0);

        // Only name a shift when one genuinely dominates that hour's Excess
        // (40%+) — an even split across several TSAs isn't "mostly" anyone's
        // shift, and naming the top of a near-tie would overstate it.
        $shiftPhrase = '';
        $actionShiftPhrase = '';
        if ($excessByTsa->isNotEmpty()) {
            $topTsaKey = $excessByTsa->sortDesc()->keys()->first();
            $topExcess = $excessByTsa[$topTsaKey];
            if ($topExcess >= 0.4 * $peakExcess) {
                $topName = $topTsaKey === '__unassigned__'
                    ? 'Unassigned'
                    : (TsaShift::where('tsa_key', $topTsaKey)->first()?->display_name ?? $topTsaKey);
                $shiftPhrase = $topExcess >= $peakExcess
                    ? " — entirely from {$topName}'s shift"
                    : " — mostly from {$topName}'s shift ({$topExcess} of {$peakExcess})";
                $actionShiftPhrase = " (especially {$topName}'s shift)";
            }
        }

        return $this->card(
            'warning', 'Timing', '🕐',
            "Excess leads peak around {$this->formatHourRange($peakHour)} — {$peakExcess} over the last " . self::LOOKBACK_DAYS . " days, more than any other hour{$shiftPhrase}. Consider extra coverage then.",
            "Schedule extra coverage around {$this->formatHourRange($peakHour)}{$actionShiftPhrase}."
        );
    }

    /** The single worst day in the window for Excess, if it's genuinely an
     *  outlier (not just the naturally busiest day) — at least double the
     *  average of every OTHER day, and at least MIN_EXCESS_SPIKE_COUNT
     *  leads so a quiet week's tiny numbers don't trip a 2x threshold. */
    private function excessSpikeDayCard(Collection $activityOrders): ?array
    {
        $byDate = $activityOrders->groupBy(fn (Order $o) => $o->pancake_created_at->toDateString());
        // Fewer than 4 days in range isn't enough to call one an outlier
        // against "the others."
        if ($byDate->count() < 4) {
            return null;
        }

        $excessByDate = $byDate->map(fn ($orders) => ProductPerformance::tally($orders)['excess']);
        $maxDate = $excessByDate->sortDesc()->keys()->first();
        $maxExcess = $excessByDate[$maxDate];

        if ($maxExcess < self::MIN_EXCESS_SPIKE_COUNT) {
            return null;
        }

        $others = $excessByDate->except($maxDate);
        $avgOthers = $others->avg();
        if ($avgOthers <= 0 || $maxExcess < self::EXCESS_SPIKE_MULTIPLIER * $avgOthers) {
            return null;
        }

        $multiple = round($maxExcess / $avgOthers, 1);
        $dateLabel = Carbon::parse($maxDate)->format('M j');

        return $this->card(
            'critical', 'Timing',  '⚠️',
            "{$dateLabel} had {$maxExcess} excess leads — about {$multiple}x the {$this->fmt($avgOthers)}-lead daily average for the rest of this window.",
            "Review what drove the {$dateLabel} spike (lead source, promo, ad push) so staffing can be ready if it repeats."
        );
    }

    /** Per-TSA RTS rate (returned ÷ (returned + delivered) upsell revenue)
     *  over the lookback window, flagged only when notably above their OWN
     *  TEAM's average — same is_returned_upsell/returned_upsell_amount vs
     *  is_upsell+status=3/amount split RtsReportController itself uses,
     *  just with a rate + relative comparison on top instead of a raw
     *  table. */
    private function rtsRateCards(Collection $shifts, Collection $teamOrders): Collection
    {
        $ordersByTsa = $teamOrders->groupBy(fn ($o) => $o->tsa_name ?? '__unassigned__');

        $rates = [];
        foreach ($shifts as $shift) {
            $orders = $ordersByTsa->get($shift->tsa_key, collect());
            if ($orders->isEmpty()) {
                continue;
            }

            $rts = (float) $orders->where('is_returned_upsell', true)->sum('returned_upsell_amount');
            $delivered = (float) $orders->filter(fn (Order $o) => $o->is_upsell && $o->status_code === 3)->sum('amount');
            $sample = $rts + $delivered;

            if ($sample < self::MIN_RTS_SAMPLE_PESOS) {
                continue;
            }

            $rates[] = ['shift' => $shift, 'rate' => $rts / $sample * 100, 'rts' => $rts, 'sample' => $sample];
        }

        // Need at least 3 TSAs with a trustworthy sample to have a
        // meaningful "team average" to compare anyone against.
        if (count($rates) < 3) {
            return collect();
        }

        $teamAvg = array_sum(array_column($rates, 'rate')) / count($rates);

        $cards = collect();
        foreach ($rates as $r) {
            if ($r['rate'] - $teamAvg < self::RTS_RATE_GAP_POINTS) {
                continue;
            }

            $cards->push($this->card(
                'warning', 'Returns', '🚚',
                "{$r['shift']->display_name}'s RTS rate is {$this->fmt($r['rate'])}% of upsell revenue over the last " . self::LOOKBACK_DAYS . " days, vs. their team's {$this->fmt($teamAvg)}% average (₱" . number_format($r['rts'], 0) . ' returned of ₱' . number_format($r['sample'], 0) . ' total).',
                "Review {$r['shift']->display_name}'s recent upsell orders for a pattern — may point to product-fit or expectation-setting coaching."
            ));
        }

        return $cards;
    }

    /** Per-TSA: upsells confirmed that later got cancelled — explicit
     *  request, 2026-08-27, from a real EOD report: "24 upsells sa EOD,
     *  ngunit naging 19 final upsells dahil sa mga cancel upsell." Reuses
     *  Order::isBroadRealUpsell()'s own exclusion of cancelled orders (see
     *  its doc comment: `!$order->is_cancelled_upsell && ...`), so "gross"
     *  here is exactly upsell_confirmation (the NET count, cancelled ones
     *  already excluded) plus the cancelled count added back — not a
     *  separate/parallel definition of "upsell." Needs both a minimum
     *  absolute count and a minimum share of gross upsells before it's a
     *  pattern worth a card, not just one isolated cancellation. */
    private function cancelledUpsellCards(Collection $shifts, Collection $teamOrders, Carbon $referenceDate): Collection
    {
        $cards = collect();
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $ordersByTsa = $teamOrders->groupBy(fn ($o) => $o->tsa_name ?? '__unassigned__');
        $refKey = $referenceDate->toDateString();
        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');

        foreach ($shifts as $shift) {
            $tsaOrders = $ordersByTsa->get($shift->tsa_key, collect());
            $dayOrders = $tsaOrders->filter(fn ($o) => $attributionDate($o) === $refKey);

            $row = ProductPerformance::tally($dayOrders);
            $cancelledOrders = $dayOrders->where('is_cancelled_upsell', true);
            $cancelledCount = $cancelledOrders->count();
            $grossUpsells = $row['upsell_confirmation'] + $cancelledCount;

            if ($cancelledCount < self::MIN_CANCELLED_UPSELLS || $grossUpsells === 0) {
                continue;
            }
            $share = $cancelledCount / $grossUpsells;
            if ($share < self::CANCELLED_SHARE_THRESHOLD) {
                continue;
            }

            $cancelledAmount = (float) $cancelledOrders->whereNotNull('cancelled_upsell_amount')->sum('cancelled_upsell_amount');

            $cards->push($this->card(
                'warning', 'Cancellations', '❌',
                "{$shift->display_name} confirmed {$grossUpsells} upsells {$dayWord}, but {$cancelledCount} were later cancelled (₱" . number_format($cancelledAmount, 0) . ") — {$row['upsell_confirmation']} net.",
                "Have QA review {$shift->display_name}'s cancelled-upsell call recordings to find the pattern behind the cancellations."
            ));
        }

        return $cards;
    }

    /** Per-product: zero confirmed upsells despite a real number of leads
     *  that day — explicit request, 2026-08-27, from a real EOD report's own
     *  action-plan goal: "Pagkakaroon ng sales sa bawat product na hawak ng
     *  team" (having sales in every product the team handles). Reuses
     *  ProductPerformance::buildRow()'s own product-matching, the exact
     *  same one every product table in this app already counts from. */
    private function zeroSalesProductCards(string $orderTeam, Collection $teamOrders, Carbon $referenceDate): Collection
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $dayOrders = $teamOrders->filter(fn ($o) => $attributionDate($o) === $refKey);
        if ($dayOrders->isEmpty()) {
            return collect();
        }

        $teamProducts = Product::where('team', $orderTeam)->orderBy('sort_order')->get();
        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');
        $cards = collect();

        foreach ($teamProducts as $product) {
            $row = ProductPerformance::buildRow($product, $dayOrders, $teamProducts);
            if ($row['total'] < self::MIN_LEADS_FOR_ZERO_SALES_CHECK || $row['upsell_confirmation'] > 0) {
                continue;
            }

            $cards->push($this->card(
                'warning', 'Product', '📦',
                "{$product->display_name} had {$row['total']} leads {$dayWord} but zero confirmed upsells.",
                "Check {$product->display_name}'s script/positioning and current stock status — {$row['total']} leads with no upsell is worth investigating."
            ));
        }

        return $cards;
    }

    /** Whole-shop (every team combined) selected-day vs. the day before —
     *  explicit request, 2026-08-27: "day by day like it will compare days
     *  ... today vs yesterday." Deliberately shop-wide rather than per-TSA:
     *  tsaTrendCards() above already covers "this TSA vs their own 14-day
     *  average" — this is the simpler, broader "yesterday vs today" read a
     *  supervisor asked for on top of that, using New Leads volume
     *  ('total', same column Leads Report/TSA Performance call it) and
     *  overall conversion rate. Excess deliberately isn't repeated here —
     *  excessSpikeDayCard() above already flags an outlier day within the
     *  window; a second day-over-day Excess comparison would just double
     *  up on the same signal. */
    private function dayOverDayCards(Collection $attributionOrders, Carbon $referenceDate): Collection
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $prevKey = $referenceDate->copy()->subDay()->toDateString();

        $refOrders = $attributionOrders->filter(fn ($o) => $attributionDate($o) === $refKey);
        $prevOrders = $attributionOrders->filter(fn ($o) => $attributionDate($o) === $prevKey);

        // Both days need a trustworthy minimum volume — comparing a quiet
        // day (or a day with no data synced yet) against a busy one would
        // produce a swing that's really just missing data, not a real trend.
        if ($refOrders->count() < self::MIN_DAY_VOLUME || $prevOrders->count() < self::MIN_DAY_VOLUME) {
            return collect();
        }

        $refRow = ProductPerformance::tally($refOrders);
        $prevRow = ProductPerformance::tally($prevOrders);

        $label = $referenceDate->isToday() ? 'Today' : $referenceDate->format('M j');
        $verb = $referenceDate->isToday() ? 'is' : 'was';
        $dayWord = $referenceDate->isToday() ? 'today' : $referenceDate->format('M j');
        $cards = collect();

        if ($prevRow['total'] > 0) {
            $volDeltaPct = round((($refRow['total'] - $prevRow['total']) / $prevRow['total']) * 100, 1);
            if (abs($volDeltaPct) >= self::VOLUME_DELTA_PERCENT) {
                $direction = $volDeltaPct > 0 ? 'up' : 'down';
                $cards->push($this->card(
                    $volDeltaPct > 0 ? 'info' : 'warning', 'Daily trend', $volDeltaPct > 0 ? '📊' : '📉',
                    "{$label}'s New Leads count {$verb} {$refRow['total']}, {$direction} " . $this->fmt(abs($volDeltaPct)) . "% from {$prevRow['total']} the day before.",
                    $volDeltaPct > 0
                        ? 'Confirm shift coverage can handle the higher volume; watch for a resulting Excess increase.'
                        : 'Check whether the drop is a sync/data gap or a real volume drop before assuming a problem.'
                ));
            }
        }

        if ($refRow['answered'] >= self::MIN_ANSWERED_FOR_RATE && $prevRow['answered'] >= self::MIN_ANSWERED_FOR_RATE
            && $refRow['conversion_rate'] !== null && $prevRow['conversion_rate'] !== null) {
            $delta = $refRow['conversion_rate'] - $prevRow['conversion_rate'];
            if (abs($delta) >= self::DAY_CONVERSION_DELTA_POINTS) {
                $cards->push($this->card(
                    $delta > 0 ? 'positive' : 'warning', 'Daily trend', $delta > 0 ? '📈' : '📉',
                    "{$label}'s overall conversion rate {$verb} {$refRow['conversion_rate']}%, " . ($delta > 0 ? 'up' : 'down') . ' ' . $this->fmt(abs($delta)) . "pts from {$this->fmt($prevRow['conversion_rate'])}% the day before.",
                    $delta > 0
                        ? "Note what changed {$dayWord} — worth repeating across the team."
                        : 'Investigate the drop — check the disposition mix and hourly breakdown for where it concentrated.'
                ));
            }
        }

        return $cards;
    }

    /** Whole-shop trailing 7 days ending on the selected date vs. the 7 days
     *  before that — explicit request, 2026-08-27: "i want has weekly data."
     *  Same New Leads volume + overall conversion rate lens as
     *  dayOverDayCards(), just widened to a week so a supervisor sees a
     *  trend that a single noisy day wouldn't show. Both weeks come out of
     *  the SAME 14-day $attributionOrders fetch generate() already made —
     *  LOOKBACK_DAYS is exactly 2 weeks for this reason. */
    private function weekOverWeekCards(Collection $attributionOrders, Carbon $referenceDate): Collection
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();

        $thisWeekFrom = $referenceDate->copy()->subDays(6)->toDateString();
        $thisWeekTo   = $referenceDate->toDateString();
        $lastWeekFrom = $referenceDate->copy()->subDays(13)->toDateString();
        $lastWeekTo   = $referenceDate->copy()->subDays(7)->toDateString();

        $inRange = fn (string $date, string $from, string $to) => $date >= $from && $date <= $to;

        $thisWeekOrders = $attributionOrders->filter(fn ($o) => $inRange($attributionDate($o), $thisWeekFrom, $thisWeekTo));
        $lastWeekOrders = $attributionOrders->filter(fn ($o) => $inRange($attributionDate($o), $lastWeekFrom, $lastWeekTo));

        if ($thisWeekOrders->count() < self::MIN_WEEK_VOLUME || $lastWeekOrders->count() < self::MIN_WEEK_VOLUME) {
            return collect();
        }

        $thisWeekRow = ProductPerformance::tally($thisWeekOrders);
        $lastWeekRow = ProductPerformance::tally($lastWeekOrders);

        $cards = collect();

        if ($lastWeekRow['total'] > 0) {
            $volDeltaPct = round((($thisWeekRow['total'] - $lastWeekRow['total']) / $lastWeekRow['total']) * 100, 1);
            if (abs($volDeltaPct) >= self::VOLUME_DELTA_PERCENT) {
                $direction = $volDeltaPct > 0 ? 'up' : 'down';
                $cards->push($this->card(
                    $volDeltaPct > 0 ? 'info' : 'warning', 'Weekly trend', $volDeltaPct > 0 ? '📊' : '📉',
                    "This week's New Leads total is {$thisWeekRow['total']}, {$direction} " . $this->fmt(abs($volDeltaPct)) . "% from {$lastWeekRow['total']} last week.",
                    $volDeltaPct > 0
                        ? 'Confirm staffing is planned for the higher weekly volume going forward.'
                        : 'Check lead sources/sync for this week — a real drop this size is worth escalating.'
                ));
            }
        }

        if ($thisWeekRow['answered'] >= self::MIN_ANSWERED_FOR_RATE && $lastWeekRow['answered'] >= self::MIN_ANSWERED_FOR_RATE
            && $thisWeekRow['conversion_rate'] !== null && $lastWeekRow['conversion_rate'] !== null) {
            $delta = $thisWeekRow['conversion_rate'] - $lastWeekRow['conversion_rate'];
            if (abs($delta) >= self::WEEK_CONVERSION_DELTA_POINTS) {
                $cards->push($this->card(
                    $delta > 0 ? 'positive' : 'warning', 'Weekly trend', $delta > 0 ? '📈' : '📉',
                    "This week's conversion rate is {$thisWeekRow['conversion_rate']}%, " . ($delta > 0 ? 'up' : 'down') . ' ' . $this->fmt(abs($delta)) . "pts from {$this->fmt($lastWeekRow['conversion_rate'])}% last week.",
                    $delta > 0
                        ? 'Note what changed this week — worth repeating and reinforcing across the team.'
                        : 'Review this week\'s disposition mix and per-TSA trends to find where the drop concentrated.'
                ));
            }
        }

        return $cards;
    }

    /** Whole-shop selected-day vs. the day before — shared by
     *  dailyRecapCard() and dailyNarrativeCard() below, so both read the
     *  exact same numbers instead of the narrative re-parsing the recap
     *  card's own message string. null when either day is below
     *  MIN_DAY_VOLUME (not enough data on one side to trust a comparison).
     *  'refWorking'/'prevWorking' are distinct TSA counts with any order
     *  that day — explicit confirmation, 2026-08-27: a TSA with no data
     *  that day means rest day/absence, so this doubles as "how many TSAs
     *  actually worked." */
    private function dayVsPrevFacts(Collection $attributionOrders, Carbon $referenceDate): ?array
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $prevKey = $referenceDate->copy()->subDay()->toDateString();

        $refOrders = $attributionOrders->filter(fn ($o) => $attributionDate($o) === $refKey);
        $prevOrders = $attributionOrders->filter(fn ($o) => $attributionDate($o) === $prevKey);

        if ($refOrders->count() < self::MIN_DAY_VOLUME || $prevOrders->count() < self::MIN_DAY_VOLUME) {
            return null;
        }

        $refRow = ProductPerformance::tally($refOrders);
        $prevRow = ProductPerformance::tally($prevOrders);

        return [
            'refRow' => $refRow,
            'prevRow' => $prevRow,
            'refAov' => $refRow['upsell_confirmation'] > 0 ? $refRow['upsell_sales'] / $refRow['upsell_confirmation'] : 0.0,
            'prevAov' => $prevRow['upsell_confirmation'] > 0 ? $prevRow['upsell_sales'] / $prevRow['upsell_confirmation'] : 0.0,
            'refWorking' => $refOrders->pluck('tsa_name')->filter()->unique()->count(),
            'prevWorking' => $prevOrders->pluck('tsa_name')->filter()->unique()->count(),
        ];
    }

    /** Whole-shop selected-day vs. the day before, as one always-shown
     *  recap — explicit request, 2026-08-27, from two real EOD reports
     *  supplied as examples: both compare Gross Sales, Orders, Pick-up
     *  Rate, Upselling Rate, and AOV day-over-day as ROUTINE reporting,
     *  not just an anomaly worth flagging (unlike dayOverDayCards() above,
     *  which only fires past a threshold). Every line shows regardless of
     *  size — a supervisor writes this recap every single day whether or
     *  not any single number moved a lot. Also notes when the number of
     *  distinct working TSAs changed (a real report blamed a drop on
     *  "kulang na manpower" — 3 TSAs working vs. 4 the day before). Severity/
     *  action are the only threshold-gated parts — decided from Orders/
     *  Pick-up Rate/Upselling Rate specifically, the 3 numbers a supervisor
     *  would call "the result." */
    private function dailyRecapCard(Collection $attributionOrders, Carbon $referenceDate): ?array
    {
        $facts = $this->dayVsPrevFacts($attributionOrders, $referenceDate);
        if ($facts === null) {
            return null;
        }
        ['refRow' => $refRow, 'prevRow' => $prevRow, 'refAov' => $refAov, 'prevAov' => $prevAov, 'refWorking' => $refWorking, 'prevWorking' => $prevWorking] = $facts;

        $lines = array_filter([
            $this->deltaCountPhrase('New Leads', $refRow['total'], $prevRow['total']),
            $this->deltaCountPhrase('Gross Sales', $refRow['upsell_sales'], $prevRow['upsell_sales'], true),
            $this->deltaCountPhrase('Orders', $refRow['upsell_confirmation'], $prevRow['upsell_confirmation']),
            $this->deltaRatePhrase('Pick-up Rate', $refRow['pick_up_rate'], $prevRow['pick_up_rate']),
            $this->deltaRatePhrase('Upselling Rate', $refRow['upselling_rate'], $prevRow['upselling_rate']),
            $this->deltaCountPhrase('AOV', $refAov, $prevAov, true),
        ], fn ($line) => $line !== null);

        // Tone comes from Orders/Pick-up Rate/Upselling Rate specifically —
        // the 3 numbers a supervisor's own report calls "the result" —
        // rather than every line, so a rising AOV alongside falling Orders
        // doesn't wash out into a falsely neutral "info" read.
        $signals = [
            $refRow['upsell_confirmation'] <=> $prevRow['upsell_confirmation'],
            ($refRow['pick_up_rate'] !== null && $prevRow['pick_up_rate'] !== null) ? ($refRow['pick_up_rate'] <=> $prevRow['pick_up_rate']) : 0,
            ($refRow['upselling_rate'] !== null && $prevRow['upselling_rate'] !== null) ? ($refRow['upselling_rate'] <=> $prevRow['upselling_rate']) : 0,
        ];
        $downSignals = count(array_filter($signals, fn ($s) => $s < 0));
        $upSignals = count(array_filter($signals, fn ($s) => $s > 0));
        $severity = $downSignals >= 2 ? 'warning' : (($upSignals >= 2 && $downSignals === 0) ? 'positive' : 'info');
        $icon = $severity === 'warning' ? '📉' : ($severity === 'positive' ? '📈' : '📋');

        $label = $referenceDate->isToday() ? 'Today' : $referenceDate->format('M j');

        $workingNote = '';
        if ($refWorking !== $prevWorking) {
            $workingNote = " {$refWorking} TSA" . ($refWorking === 1 ? '' : 's') . ' worked vs. ' . $prevWorking . ' the day before'
                . ($refWorking < $prevWorking ? ' — fewer hands on deck likely explains part of this.' : '.');
        }

        $message = "{$label} vs. the day before: " . implode(', ', $lines) . '.' . $workingNote;

        return $this->card($severity, 'Daily recap', $icon, $message,
            $severity === 'warning'
                ? 'Review the disposition mix and working-TSA count before assuming this is a pure performance issue.'
                : null
        );
    }

    /** Shared by dailyRecapCard() — a count/peso value's day-over-day
     *  delta, phrased as "{label} {value} ({direction} {pct}% from
     *  {previous})". $prev <= 0 skips the percentage (division by zero has
     *  no meaningful "% change" reading) and just states the current value. */
    private function deltaCountPhrase(string $label, float $ref, float $prev, bool $isPeso = false): string
    {
        $fmt = fn (float $v) => $isPeso ? '₱' . number_format($v, 0) : $this->fmt($v);

        if ($prev <= 0) {
            return "{$label} {$fmt($ref)}";
        }

        $deltaPct = round((($ref - $prev) / $prev) * 100, 1);
        if ($deltaPct === 0.0) {
            return "{$label} {$fmt($ref)} (flat vs. {$fmt($prev)})";
        }

        $dir = $deltaPct > 0 ? 'up' : 'down';
        return "{$label} {$fmt($ref)} ({$dir} " . $this->fmt(abs($deltaPct)) . "% from {$fmt($prev)})";
    }

    /** Shared by dailyRecapCard() — a percentage rate's day-over-day delta
     *  in percentage POINTS (not percent-of-percent), same convention as
     *  every other rate comparison in this file. null when either day
     *  lacks a trustworthy rate (0 answered/called that day). */
    private function deltaRatePhrase(string $label, ?float $ref, ?float $prev): ?string
    {
        if ($ref === null || $prev === null) {
            return null;
        }

        $delta = round($ref - $prev, 1);
        if ($delta === 0.0) {
            return "{$label} {$this->fmt($ref)}% (flat)";
        }

        $dir = $delta > 0 ? 'up' : 'down';
        return "{$label} {$this->fmt($ref)}% ({$dir} " . $this->fmt(abs($delta)) . "pts from {$this->fmt($prev)}%)";
    }

    /** Whole-shop, plain-language paragraph synthesizing the whole day —
     *  explicit request, 2026-08-27: "overall insights... paragraph...
     *  reasons behind of all data... i want to make it fully insights like
     *  for AI... but i dont want to integrate any AI... everyday is
     *  changing, not by format." No LLM call — every fact here is read
     *  straight off the SAME computations the other cards already made
     *  (dayVsPrevFacts()/rankedConversionCandidates()/$cards' own
     *  categories), never invented and never a re-parse of another card's
     *  message string. "Not by format" is handled two ways: (1) each
     *  sentence only appears when its underlying signal is actually present
     *  that day — a quiet day with no bottom performer just skips that
     *  sentence rather than a limp "no concerns to report" filler, so the
     *  PARAGRAPH'S SHAPE changes day to day, not just its numbers; (2) each
     *  sentence that does appear is chosen from a small pool of equivalent
     *  phrasings, picked by hashing the date (+ team, if scoped) — the same
     *  day always reads identically on every reload (no flicker), but a
     *  different day reads differently even given a similar mix of
     *  signals. Rendered as its own full-width prose block in insights.
     *  blade.php (category 'Overview'), not the card grid. */
    private function dailyNarrativeCard(Collection $attributionOrders, Carbon $referenceDate, Collection $cards, ?string $team): ?array
    {
        $facts = $this->dayVsPrevFacts($attributionOrders, $referenceDate);
        if ($facts === null) {
            return null;
        }

        $refRow = $facts['refRow'];
        $prevRow = $facts['prevRow'];
        $dayWord = $referenceDate->isToday() ? 'Today' : $referenceDate->format('M j');

        // Deterministic per (date, team) — same seed every reload of the
        // same day, different across days/teams.
        $seedBase = $referenceDate->toDateString() . '|' . ($team ?? 'all');
        $pick = fn (array $options, string $tag) => $options[hexdec(substr(md5($seedBase . '|' . $tag), 0, 8)) % count($options)];

        // Same 3-signal tone read as dailyRecapCard() — Orders/Pick-up
        // Rate/Upselling Rate are "the result" a supervisor's own report
        // actually leads with.
        $signals = [
            $refRow['upsell_confirmation'] <=> $prevRow['upsell_confirmation'],
            ($refRow['pick_up_rate'] !== null && $prevRow['pick_up_rate'] !== null) ? ($refRow['pick_up_rate'] <=> $prevRow['pick_up_rate']) : 0,
            ($refRow['upselling_rate'] !== null && $prevRow['upselling_rate'] !== null) ? ($refRow['upselling_rate'] <=> $prevRow['upselling_rate']) : 0,
        ];
        $downSignals = count(array_filter($signals, fn ($s) => $s < 0));
        $upSignals = count(array_filter($signals, fn ($s) => $s > 0));
        $tone = $downSignals >= 2 ? 'down' : (($upSignals >= 2 && $downSignals === 0) ? 'up' : 'mixed');

        $sentences = [];

        $openings = [
            'down' => [
                "{$dayWord} came in softer than the day before.",
                "{$dayWord}'s numbers slipped compared to yesterday.",
                "The team lost some ground {$dayWord} relative to the previous day.",
            ],
            'up' => [
                "{$dayWord} was a genuinely strong day.",
                "{$dayWord}'s results improved nicely over yesterday.",
                "The team pushed the numbers forward {$dayWord}.",
            ],
            'mixed' => [
                "{$dayWord}'s results were a mixed bag compared to yesterday.",
                "{$dayWord} landed somewhere in between yesterday's numbers — a few things up, a few things down.",
                "Nothing moved dramatically {$dayWord}, just a split day.",
            ],
        ];
        $sentences[] = $pick($openings[$tone], 'opening');

        $orderDelta = $refRow['upsell_confirmation'] - $prevRow['upsell_confirmation'];
        $orderWord = $orderDelta > 0 ? 'up' : ($orderDelta < 0 ? 'down' : 'flat');
        $rateBits = array_filter([
            $refRow['pick_up_rate'] !== null ? "Pick-up Rate at {$this->fmt($refRow['pick_up_rate'])}%" : null,
            $refRow['upselling_rate'] !== null ? "Upselling Rate at {$this->fmt($refRow['upselling_rate'])}%" : null,
        ]);
        $sentences[] = "{$refRow['total']} new leads came in and {$refRow['upsell_confirmation']} orders were confirmed ({$orderWord} " . abs($orderDelta) . ' from yesterday), with ' . implode(' and ', $rateBits) . '.';

        // Manpower — only when working-TSA count actually DROPPED (matches
        // the real report's own "kulang na manpower" framing; a rise in
        // headcount isn't a concern worth narrating here).
        if ($facts['refWorking'] < $facts['prevWorking']) {
            $sentences[] = $pick([
                "Only {$facts['refWorking']} TSAs were working (vs. {$facts['prevWorking']} yesterday), which likely explains part of the gap.",
                "Manpower was thinner today — {$facts['refWorking']} vs. {$facts['prevWorking']} TSAs the day before.",
                "With {$facts['refWorking']} of the usual {$facts['prevWorking']} TSAs in, capacity was down before any performance factor even comes into it.",
            ], 'manpower');
        }

        $candidates = $this->rankedConversionCandidates($attributionOrders, $referenceDate);
        if (count($candidates) >= 2) {
            $best = $candidates[0];
            $worst = $candidates[count($candidates) - 1];
            if ($best['rate'] > 0) {
                $sentences[] = $pick([
                    "{$best['name']} led the day at {$best['rate']}% conversion ({$best['upsells']} upsells).",
                    "On the strong side, {$best['name']} closed out at {$best['rate']}% conversion.",
                    "{$best['name']}'s {$best['rate']}% conversion rate was the standout today.",
                ], 'top');
            }
            if ($worst['rate'] <= self::BOTTOM_PERFORMER_MAX_RATE && $worst['name'] !== $best['name']) {
                $sentences[] = $pick([
                    "{$worst['name']} struggled by comparison, at {$worst['rate']}% conversion — worth a check-in.",
                    "On the other end, {$worst['name']}'s {$worst['rate']}% conversion rate needs attention.",
                    "{$worst['name']} is the one to coach up, sitting at {$worst['rate']}% today.",
                ], 'bottom');
            }
        }

        // These 3 signals are read from $cards BY CATEGORY (a cheap,
        // reliable structural check) — never by parsing another card's
        // message text.
        $targetMissCount = $cards->where('category', 'Target metrics')->count();
        if ($targetMissCount > 0) {
            $tsaWord = $targetMissCount === 1 ? 'TSA' : 'TSAs';
            $sentences[] = $pick([
                "{$targetMissCount} {$tsaWord} missed at least one daily target — see Target Metrics below for specifics.",
                "Daily targets weren't fully hit by {$targetMissCount} {$tsaWord} — details below.",
            ], 'targets');
        }

        if ($cards->contains(fn ($c) => $c['category'] === 'Cancellations')) {
            $sentences[] = $pick([
                'A meaningful share of confirmed upsells were later cancelled — worth a QA pass on those calls.',
                "Some upsells that looked confirmed didn't stick — cancellations ate into the real number.",
            ], 'cancellations');
        }

        if ($cards->contains(fn ($c) => $c['category'] === 'Timing' && $c['severity'] !== 'positive')) {
            $sentences[] = $pick([
                'Excess leads are still concentrated in specific hours — coverage timing is worth another look.',
                'A clear peak-hour pattern in Excess leads suggests staffing could shift to match demand better.',
            ], 'timing');
        }

        $closings = [
            'down' => [
                'Focus tomorrow: shore up the gaps above before they compound.',
                "Tomorrow's priority is recovering the ground lost today.",
            ],
            'up' => [
                'Keep the momentum — repeat whatever drove today and watch for it to hold.',
                "The goal now is making today's result the new normal, not a one-off.",
            ],
            'mixed' => [
                "Tomorrow, double down on what worked and address what didn't.",
                'A day like this is mostly about fixing the specific gaps, not a wholesale change.',
            ],
        ];
        $sentences[] = $pick($closings[$tone], 'closing');

        $severity = $tone === 'down' ? 'warning' : ($tone === 'up' ? 'positive' : 'info');
        return $this->card($severity, 'Overview', '📝', implode(' ', $sentences));
    }

    /** @param string|null $action A concrete next step, in the SAME plain-
     *  language style as $message — explicit request, 2026-08-27: "action
     *  plan is smart report too based of the accurate data." Every action
     *  is derived from the exact numbers already computed for $message, not
     *  a separate/parsed re-reading of it — the Action Plan view (insights.
     *  blade.php, $view === 'action-plan') just filters to cards that have
     *  one instead of re-deriving anything. null for the rare card with
     *  nothing to actually DO about it. */
    private function card(string $severity, string $category, string $icon, string $message, ?string $action = null): array
    {
        return [
            'severity' => $severity,
            'category' => $category,
            'icon'     => $icon,
            'message'  => $message,
            'action'   => $action,
        ];
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.');
    }

    private function formatHourRange(int $hour): string
    {
        $start = Carbon::createFromTime($hour, 0);
        $end = $start->copy()->addHour();
        return $start->format('g A') . '–' . $end->format('g A');
    }
}
