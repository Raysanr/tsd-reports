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
        // hourly/daily trends already use — needed for peakExcessHourCard()
        // below. Using the wrong one for either would silently disagree with
        // the page a supervisor would go double-check the card against.
        // whereIn('team', $orderTeams) here (not a filter after ->get()) is
        // what scopes EVERY card below to the selected team — the day/week/
        // timing cards never touch config('teams') themselves, so this is
        // the only place team-scoping happens for them.
        //
        // $activityOrders is scoped to just $referenceDate, NOT the full
        // 14-day $rangeFrom/$rangeTo window — it used to feed both
        // peakExcessHourCard() and excessSpikeDayCard(), the latter of which
        // genuinely needed the full window to find an outlier day. That card
        // was removed 2026-09-01 (explicit request: naming a DIFFERENT day,
        // e.g. "Aug 18 had 248 excess leads," while viewing a filtered Aug
        // 31 was confusing — there's no date-RANGE mode on this page, every
        // visit is a single selected day, so a card whose whole point is "a
        // different day than the one you picked" never fit it). Left at the
        // narrower single-day range since the only remaining reader
        // (peakExcessHourCard()) doesn't need more than that.
        $attributionOrders = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [$rangeFrom, $rangeTo]
        )->whereIn('team', $orderTeams)->get();
        $activityOrders = Order::whereBetween('pancake_created_at', [$referenceDate->copy()->startOfDay(), $referenceDate->copy()->endOfDay()])
            ->whereIn('team', $orderTeams)->get();

        // $matchPool/$products — explicit request, 2026-09-01: Overview's "New
        // Leads"/Daily Recap totals disagreed with Leads Report/TSA Performance's
        // own Grand Total (395 vs 383, confirmed live) because dayVsPrevFacts()
        // used to tally() $attributionOrders directly (every order in range, no
        // product-matching), while Leads Report/TSA Performance define "total
        // leads" as the SUM of each configured product's own matched orders — an
        // order that matches NO product at all is silently excluded there (see
        // LeadsReportController::indexAll()'s own comment: "An untracked-product
        // order is simply not counted here"). $matchPool is deliberately NOT
        // scoped to $orderTeams (unlike $attributionOrders above) for the exact
        // same reason LeadsReportController::index()'s own $matchPool isn't — a
        // cross-team combo order (e.g. a Pterygium order bundling Sinuxyl units)
        // would otherwise be invisible to the other team's product row.
        $matchPool = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [$rangeFrom, $rangeTo]
        )->whereIn('team', collect($teamsConfig)->pluck('order_team')->all())->get();
        $products = $team && array_key_exists($team, $teamsConfig)
            ? Product::where('team', $teamsConfig[$team]['order_team'])->orderBy('sort_order')->get()
            : Product::orderBy('sort_order')->get();

        $cards = collect();
        $allShifts = collect();

        foreach ($selectedTeams as $teamConfig) {
            $orderTeam = $teamConfig['order_team'];
            $shifts = TsaShift::where('team', $orderTeam)->orderBy('sort_order')->get();
            $teamOrders = $attributionOrders->where('team', $orderTeam);
            $allShifts = $allShifts->merge($shifts);

            $cards = $cards->merge($this->tsaTrendCards($shifts, $teamOrders, $referenceDate));
            $cards = $cards->merge($this->rtsRateCards($shifts, $teamOrders));
            $cards = $cards->merge($this->targetMetricsCards($shifts, $teamOrders, $referenceDate, $orderTeam));
            $cards = $cards->merge($this->cancelledUpsellCards($shifts, $teamOrders, $referenceDate));
            $cards = $cards->merge($this->zeroSalesProductCards($orderTeam, $teamOrders, $referenceDate));
        }

        // Computed ONCE here and threaded through to every card that needs
        // them — explicit request, 2026-08-27, root-caused against real
        // production data (177k+ orders): topPerformerCard()/
        // bottomPerformerCard()/dailyNarrativeCard() all used to call
        // rankedConversionCandidates() independently (3 full re-groupings of
        // the day's orders), and dailyRecapCard()/dailyNarrativeCard() both
        // called dayVsPrevFacts() independently (2 full re-filterings) —
        // redundant work that measurably slowed every request and made the
        // topbar filter buttons' race-condition window (see app.js's
        // softRefresh/submit-handler comments) wider than it needed to be.
        $candidates = $this->rankedConversionCandidates($attributionOrders, $referenceDate);
        $dayVsPrevFacts = $this->dayVsPrevFacts($matchPool, $products, $referenceDate);

        if ($card = $this->topPerformerCard($candidates, $referenceDate)) {
            $cards->push($card);
        }
        if ($card = $this->bottomPerformerCard($candidates, $referenceDate)) {
            $cards->push($card);
        }
        if ($card = $this->peakExcessHourCard($activityOrders, $referenceDate)) {
            $cards->push($card);
        }

        $cards = $cards->merge($this->dayOverDayCards($attributionOrders, $referenceDate));
        $cards = $cards->merge($this->weekOverWeekCards($attributionOrders, $referenceDate));
        if ($card = $this->dailyRecapCard($dayVsPrevFacts, $referenceDate)) {
            $cards->push($card);
        }

        // Computed LAST — the EOD report's Action Plan section reads target-
        // miss/cancellation signals directly off $cards already built above
        // (by category, never by parsing another card's message text).
        if ($card = $this->eodReportCard($matchPool, $products, $allShifts, $activityOrders, $referenceDate, $cards, $team, $teamsConfig)) {
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
        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');

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
                    "Bumaba ang Conversion Rate ni {$shift->display_name} sa {$refRate}% {$dayWord}, malayo sa {$this->fmt($baseline)}% average niya sa nakaraang " . self::LOOKBACK_DAYS . ' days — dapat i-check-in.',
                    "I-check-in si {$shift->display_name} — alamin kung ano ang dahilan ng pagbaba (personal issue, technical problem, o lead-quality issue) bago pa ito lumala."
                ));
            } elseif ($delta >= self::TREND_DELTA_POINTS) {
                $cards->push($this->card(
                    'positive', 'TSA performance', '📈',
                    "Tumaas ang Conversion Rate ni {$shift->display_name} sa {$refRate}% {$dayWord}, mas mataas sa {$this->fmt($baseline)}% average niya.",
                    "Tanungin si {$shift->display_name} kung ano ang ginawa niya {$dayWord} — dapat malaman para maulit ng ibang TSA."
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
        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');

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
                "Hindi na-achieve ni {$shift->display_name} ang " . count($misses) . ' of 5 daily targets ' . $dayWord . ': ' . implode(', ', $misses) . '.',
                "Mag-schedule ng coaching check-in kay {$shift->display_name}, focused sa: " . implode(', ', $misses) . '.'
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

        return "galing sa {$topLabel} ({$topCount} sa {$row['unanswered']} unanswered)";
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

        return "majority ay galing sa {$best->display_name} orders";
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
     *  number. $candidates comes from generate()'s own single shared call
     *  to rankedConversionCandidates() — see that method's own doc comment. */
    private function topPerformerCard(array $candidates, Carbon $referenceDate): ?array
    {
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

        $label = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');
        return $this->card(
            'positive', 'TSA performance', '🏆',
            "Si {$best['name']} ang top performer {$label}: {$best['rate']}% conversion rate, {$best['upsells']} upsells confirmed.",
            "Kilalanin si {$best['name']} para sa result {$label} — pwedeng i-share ang approach niya sa team."
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
    private function bottomPerformerCard(array $candidates, Carbon $referenceDate): ?array
    {
        if (count($candidates) < 2) {
            return null;
        }

        $worst = $candidates[count($candidates) - 1];
        if ($worst['rate'] > self::BOTTOM_PERFORMER_MAX_RATE) {
            return null;
        }

        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');
        return $this->card(
            'warning', 'TSA performance', '🔻',
            "Ang Conversion Rate ni {$worst['name']} ay {$worst['rate']}% {$dayWord}, ang pinakamababa sa comparable TSAs ({$worst['answered']} answered calls).",
            "I-coach si {$worst['name']} sa call handling at conversation-to-conversion technique — focus sa pag-convert ng mas maraming answered calls papuntang confirmed upsells."
        );
    }

    /** Which hour-of-day generates the most Excess on the SELECTED day —
     *  explicit request, 2026-09-01: used to sum across the whole 14-day
     *  lookback window regardless of which single date was picked (a
     *  supervisor filtering one specific date, e.g. Aug 31, wants that day's
     *  own peak hour/count, not "92 over the last 14 days" folded into it);
     *  $activityOrders (generate()'s own fetch) is now already scoped to
     *  just $referenceDate for this reason. Same hourly bucketing
     *  ChartsController's own "Excess Leads Trend" chart uses, just
     *  re-summed by hour instead of by day. Also names WHICH shift that
     *  Excess mostly comes from — explicit request, 2026-08-27: "anong oras
     *  tumataas ang excess leads? saang shift ito nanggagaling?" (what hour
     *  does Excess rise, which shift does it come from) — grouping that peak
     *  hour's own orders by TSA answers the second question with the same
     *  tally()['excess'] formula, just re-summed by TSA instead of by hour. */
    private function peakExcessHourCard(Collection $activityOrders, Carbon $referenceDate): ?array
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
                    ? " — galing lahat sa shift ni {$topName}"
                    : " — majority galing sa shift ni {$topName} ({$topExcess} sa {$peakExcess})";
                $actionShiftPhrase = " (lalo na sa shift ni {$topName})";
            }
        }

        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');

        return $this->card(
            'warning', 'Timing', '🕐',
            "Tumataas ang Excess leads sa {$this->formatHourRange($peakHour)} {$dayWord} — {$peakExcess} sa oras na iyon, pinakamataas sa lahat ng oras{$shiftPhrase}. Dapat magdagdag ng coverage sa oras na iyon.",
            "Mag-schedule ng extra coverage sa {$this->formatHourRange($peakHour)}{$actionShiftPhrase}."
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
                "Ang RTS rate ni {$r['shift']->display_name} ay {$this->fmt($r['rate'])}% ng upsell revenue sa nakaraang " . self::LOOKBACK_DAYS . " days, kumpara sa {$this->fmt($teamAvg)}% average ng team niya (₱" . number_format($r['rts'], 0) . ' returned sa ₱' . number_format($r['sample'], 0) . ' total).',
                "I-review ang recent upsell orders ni {$r['shift']->display_name} para hanapin ang pattern — pwedeng dahil sa product-fit o expectation-setting coaching."
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
        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');

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
                "May {$grossUpsells} upsells si {$shift->display_name} {$dayWord}, pero {$cancelledCount} dito ay na-cancel (₱" . number_format($cancelledAmount, 0) . ") — {$row['upsell_confirmation']} na lang ang net.",
                "Pa-review sa QA ang cancelled-upsell call recordings ni {$shift->display_name} para mahanap ang pattern ng mga cancellation."
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
        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');
        $cards = collect();

        foreach ($teamProducts as $product) {
            $row = ProductPerformance::buildRow($product, $dayOrders, $teamProducts);
            if ($row['total'] < self::MIN_LEADS_FOR_ZERO_SALES_CHECK || $row['upsell_confirmation'] > 0) {
                continue;
            }

            $cards->push($this->card(
                'warning', 'Product', '📦',
                "May {$row['total']} leads ang {$product->display_name} {$dayWord} pero walang confirmed upsells.",
                "I-check ang script/positioning at current stock status ng {$product->display_name} — dapat i-imbestigahan ang {$row['total']} leads na walang upsell."
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
     *  overall conversion rate. */
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

        $label = $referenceDate->isToday() ? 'Ngayong araw' : $referenceDate->format('M j');
        $dayWord = $referenceDate->isToday() ? 'ngayong araw' : $referenceDate->format('M j');
        $cards = collect();

        if ($prevRow['total'] > 0) {
            $volDeltaPct = round((($refRow['total'] - $prevRow['total']) / $prevRow['total']) * 100, 1);
            if (abs($volDeltaPct) >= self::VOLUME_DELTA_PERCENT) {
                $direction = $volDeltaPct > 0 ? 'tumaas' : 'bumaba';
                $cards->push($this->card(
                    $volDeltaPct > 0 ? 'info' : 'warning', 'Daily trend', $volDeltaPct > 0 ? '📊' : '📉',
                    "{$label}, {$direction} ang New Leads count sa {$refRow['total']}, " . $this->fmt(abs($volDeltaPct)) . "% mula sa {$prevRow['total']} kahapon.",
                    $volDeltaPct > 0
                        ? 'I-confirm kung kaya ng shift coverage ang mas mataas na volume; bantayan ang posibleng pagtaas ng Excess.'
                        : 'I-check kung sync/data gap lang ang dahilan ng pagbaba o totoong volume drop bago i-assume na problema.'
                ));
            }
        }

        if ($refRow['answered'] >= self::MIN_ANSWERED_FOR_RATE && $prevRow['answered'] >= self::MIN_ANSWERED_FOR_RATE
            && $refRow['conversion_rate'] !== null && $prevRow['conversion_rate'] !== null) {
            $delta = $refRow['conversion_rate'] - $prevRow['conversion_rate'];
            if (abs($delta) >= self::DAY_CONVERSION_DELTA_POINTS) {
                $cards->push($this->card(
                    $delta > 0 ? 'positive' : 'warning', 'Daily trend', $delta > 0 ? '📈' : '📉',
                    "{$label}, ang overall Conversion Rate ay {$refRow['conversion_rate']}%, " . ($delta > 0 ? 'tumaas' : 'bumaba') . ' ng ' . $this->fmt(abs($delta)) . "pts mula sa {$this->fmt($prevRow['conversion_rate'])}% kahapon.",
                    $delta > 0
                        ? "I-note kung ano ang nagbago {$dayWord} — dapat ulitin sa buong team."
                        : 'I-imbestigahan ang pagbaba — i-check ang disposition mix at hourly breakdown kung saan ito nag-concentrate.'
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
                $direction = $volDeltaPct > 0 ? 'tumaas' : 'bumaba';
                $cards->push($this->card(
                    $volDeltaPct > 0 ? 'info' : 'warning', 'Weekly trend', $volDeltaPct > 0 ? '📊' : '📉',
                    "Ang New Leads total ngayong linggo ay {$thisWeekRow['total']}, {$direction} ng " . $this->fmt(abs($volDeltaPct)) . "% mula sa {$lastWeekRow['total']} noong nakaraang linggo.",
                    $volDeltaPct > 0
                        ? 'I-confirm kung planado ang staffing para sa mas mataas na weekly volume papunta sa susunod.'
                        : 'I-check ang lead sources/sync ngayong linggo — dapat i-escalate kung ganito kalaki ang drop.'
                ));
            }
        }

        if ($thisWeekRow['answered'] >= self::MIN_ANSWERED_FOR_RATE && $lastWeekRow['answered'] >= self::MIN_ANSWERED_FOR_RATE
            && $thisWeekRow['conversion_rate'] !== null && $lastWeekRow['conversion_rate'] !== null) {
            $delta = $thisWeekRow['conversion_rate'] - $lastWeekRow['conversion_rate'];
            if (abs($delta) >= self::WEEK_CONVERSION_DELTA_POINTS) {
                $cards->push($this->card(
                    $delta > 0 ? 'positive' : 'warning', 'Weekly trend', $delta > 0 ? '📈' : '📉',
                    "Ang Conversion Rate ngayong linggo ay {$thisWeekRow['conversion_rate']}%, " . ($delta > 0 ? 'tumaas' : 'bumaba') . ' ng ' . $this->fmt(abs($delta)) . "pts mula sa {$this->fmt($lastWeekRow['conversion_rate'])}% noong nakaraang linggo.",
                    $delta > 0
                        ? 'I-note kung ano ang nagbago ngayong linggo — dapat ulitin at i-reinforce sa buong team.'
                        : 'I-review ang disposition mix at per-TSA trends ngayong linggo para mahanap kung saan nag-concentrate ang drop.'
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
     *  actually worked."
     *
     *  $refRow/$prevRow use ProductPerformance::sumRows() over each
     *  configured product's own buildRow() — the SAME "total leads" and
     *  "catered leads" definition Leads Report/TSA Performance's own Grand
     *  Total use (sum of per-product matched rows; an order matching NO
     *  configured product is excluded), not a raw tally() over every order
     *  in range. Fixed 2026-09-01 — confirmed live, a raw tally() showed 554
     *  "total leads" for a day Leads Report's own Grand Total showed 494,
     *  the gap being every order that didn't match any product's raw_tags/
     *  cart item at all (LeadsReportController::indexAll()'s own comment:
     *  "An untracked-product order is simply not counted here"). $matchPool
     *  (cross-team, like Leads Report's own matchPool) is what buildRow()
     *  matches against; $products is the team-scoped (or all-teams) product
     *  list to sum over. Volume-gate/working-TSA-count below still read the
     *  raw per-day order set — that's about real activity/headcount, not
     *  which orders matched a tracked product. */
    private function dayVsPrevFacts(Collection $matchPool, Collection $products, Carbon $referenceDate): ?array
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $prevKey = $referenceDate->copy()->subDay()->toDateString();

        $refOrders = $matchPool->filter(fn ($o) => $attributionDate($o) === $refKey);
        $prevOrders = $matchPool->filter(fn ($o) => $attributionDate($o) === $prevKey);

        if ($refOrders->count() < self::MIN_DAY_VOLUME || $prevOrders->count() < self::MIN_DAY_VOLUME) {
            return null;
        }

        $refRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $refOrders, $products)));
        $prevRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $prevOrders, $products)));

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
     *  would call "the result." $facts comes from generate()'s own single
     *  shared call to dayVsPrevFacts() (also fed to dailyNarrativeCard()) —
     *  null means either day was below MIN_DAY_VOLUME. */
    private function dailyRecapCard(?array $facts, Carbon $referenceDate): ?array
    {
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

        $label = $referenceDate->isToday() ? 'Ngayong araw' : $referenceDate->format('M j');

        $workingNote = '';
        if ($refWorking !== $prevWorking) {
            $workingNote = " {$refWorking} TSA ang nag-work kumpara sa {$prevWorking} kahapon"
                . ($refWorking < $prevWorking ? ' — bahagyang naipapaliwanag nito ang kakulangan dahil kulang ang manpower.' : '.');
        }

        $message = "{$label} kumpara sa kahapon: " . implode(', ', $lines) . '.' . $workingNote;

        return $this->card($severity, 'Daily recap', $icon, $message,
            $severity === 'warning'
                ? 'I-review ang disposition mix at working-TSA count bago i-assume na performance issue lang ito.'
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
            return "{$label} {$fmt($ref)} (flat, kagaya ng {$fmt($prev)})";
        }

        $dir = $deltaPct > 0 ? 'tumaas' : 'bumaba';
        return "{$label} {$fmt($ref)} ({$dir} ng " . $this->fmt(abs($deltaPct)) . "% mula sa {$fmt($prev)})";
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

        $dir = $delta > 0 ? 'tumaas' : 'bumaba';
        return "{$label} {$this->fmt($ref)}% ({$dir} ng " . $this->fmt(abs($delta)) . "pts mula sa {$this->fmt($prev)}%)";
    }

    /** Whole-shop (or single-team, if $team is scoped) structured EOD report
     *  in Markdown — explicit request, 2026-09-01: replaces the old single-
     *  paragraph dailyNarrativeCard() with a real supervisor-style EOD
     *  report (Overall Performance / TSA Performance / Lead Capacity &
     *  Distribution / Conversion Analysis / Action Plan / Summary), matching
     *  the exact structure of a real EOD report supplied as an example.
     *  Still no LLM call — every figure is read straight off
     *  ProductPerformance's own formulas, never invented.
     *
     *  NI (Net Income) is deliberately OMITTED — explicit decision,
     *  2026-09-01: this app has no cost/expense data anywhere, and Net
     *  Income needs Revenue - Costs; fabricating a number would violate this
     *  whole file's own "i want to make it all accurate" mandate (see class
     *  doc comment). Add it once a real cost figure exists to compute from.
     *
     *  Opening (6am-3pm) / Closing (3pm-12mn) — explicit decision,
     *  2026-09-01: a LEAD is classified by the HOUR it arrived in
     *  (pancake_created_at), not by which TSA later worked it — TsaShift's
     *  own shift_start times don't cleanly split into these two exact
     *  windows (confirmed live: Katherine's 1pm-10pm start straddles both).
     *  Capacity per window is (# TSAs whose OWN shift_start falls in that
     *  window) × TARGET_CATERED_LEADS (75) — that's the one place a TSA's
     *  shift actually matters here, purely for the capacity count, not for
     *  bucketing leads.
     *
     *  $matchPool/$products: same product-matched sum-of-rows definition
     *  dayVsPrevFacts() uses (see that method's own doc comment) — Total
     *  Leads/Catered Leads/Orders here must never disagree with Leads
     *  Report/TSA Performance's own numbers. $activityOrders is the
     *  SELECTED DAY's orders only (generate()'s own fetch, by
     *  pancake_created_at — the "when did this lead actually arrive" column,
     *  same one the Opening/Closing hour split needs). $teamsConfig is
     *  needed to resolve $team's display name for the report's own header
     *  line. */
    private function eodReportCard(Collection $matchPool, Collection $products, Collection $shifts, Collection $activityOrders, Carbon $referenceDate, Collection $cards, ?string $team, array $teamsConfig): ?array
    {
        $attributionDate = fn (Order $o) => ($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
        $refKey = $referenceDate->toDateString();
        $prevKey = $referenceDate->copy()->subDay()->toDateString();

        $refOrders = $matchPool->filter(fn ($o) => $attributionDate($o) === $refKey);
        $prevOrders = $matchPool->filter(fn ($o) => $attributionDate($o) === $prevKey);

        if ($refOrders->count() < self::MIN_DAY_VOLUME || $prevOrders->count() < self::MIN_DAY_VOLUME) {
            return null;
        }

        $refRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $refOrders, $products)));
        $prevRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $prevOrders, $products)));
        $refAov = $refRow['upsell_confirmation'] > 0 ? $refRow['upsell_sales'] / $refRow['upsell_confirmation'] : 0.0;

        $dateLabel = $referenceDate->format('F j, Y');
        $teamLabel = ($team && array_key_exists($team, $teamsConfig)) ? $teamsConfig[$team]['name'] : null;

        $md = [];
        $md[] = $teamLabel
            ? "# *TSA's Daily Sales Report – Team {$teamLabel}*"
            : "# *TSA's Daily Sales Report*";
        $md[] = "**{$dateLabel} | EOD Report Summary**";

        // ---- Overall Performance ------------------------------------
        $md[] = '### *Overall Performance*';
        $orderDelta = $refRow['upsell_confirmation'] - $prevRow['upsell_confirmation'];
        $orderWord = $orderDelta > 0 ? 'improvement' : ($orderDelta < 0 ? 'pagbaba' : 'flat na resulta');
        $cateredWord = $refRow['catered'] < $prevRow['catered'] ? 'bumaba' : ($refRow['catered'] > $prevRow['catered'] ? 'tumaas' : 'flat');
        $pickUpWord = ($refRow['pick_up_rate'] ?? 0) >= ($prevRow['pick_up_rate'] ?? 0) ? 'Nag-improve din' : 'Bumaba naman';
        $overall = "May **slight {$orderWord} sa orders**, from **{$prevRow['upsell_confirmation']} → {$refRow['upsell_confirmation']}**, kahit {$cateredWord} ang catered leads from **{$prevRow['catered']} → {$refRow['catered']}**.";
        if ($refRow['pick_up_rate'] !== null && $prevRow['pick_up_rate'] !== null) {
            $overall .= " {$pickUpWord} ang **pick-up rate from " . $this->fmt($prevRow['pick_up_rate']) . '% → ' . $this->fmt($refRow['pick_up_rate']) . '%**.';
        }
        $md[] = $overall;
        $md[] = "May **₱" . number_format($refAov, 2) . " AOV** ngayong araw base sa **{$refRow['upsell_confirmation']} orders** na **₱" . number_format($refRow['upsell_sales'], 0) . ' total sales**.';

        // ---- TSA Performance ------------------------------------------
        if ($shifts->isNotEmpty()) {
            $tsaRows = $shifts->map(function ($shift) use ($refOrders, $prevOrders, $products) {
                $tsaRefOrders = $refOrders->where('tsa_name', $shift->tsa_key);
                $tsaPrevOrders = $prevOrders->where('tsa_name', $shift->tsa_key);
                $refRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $tsaRefOrders, $products)));
                $prevRow = ProductPerformance::sumRows($products->map(fn ($p) => ProductPerformance::buildRow($p, $tsaPrevOrders, $products)));
                return ['shift' => $shift, 'ref' => $refRow, 'prev' => $prevRow];
            })->filter(fn ($r) => $r['ref']['catered'] >= self::MIN_CATERED_FOR_TARGET_CHECK || $r['ref']['upsell_confirmation'] > 0);

            if ($tsaRows->isNotEmpty()) {
                $md[] = '### *TSA Performance*';
                $topSales = $tsaRows->sortByDesc(fn ($r) => $r['ref']['upsell_sales'])->first();
                foreach ($tsaRows as $r) {
                    $shift = $r['shift'];
                    $row = $r['ref'];
                    $prev = $r['prev'];
                    $bits = [];
                    if ($row === $topSales['ref'] && $row['upsell_sales'] > 0) {
                        $bits[] = 'strongest positive contributor sa sales';
                    }
                    if ($row['pick_up_rate'] !== null && $row['pick_up_rate'] < self::BOTTOM_PERFORMER_MAX_RATE) {
                        $bits[] = 'main concern ang low **' . $this->fmt($row['pick_up_rate']) . '% pick-up rate**';
                    }
                    if ($prev['upsell_confirmation'] > 0 && $row['upsell_confirmation'] > $prev['upsell_confirmation']) {
                        $bits[] = "nag-improve mula **{$prev['upsell_confirmation']} → {$row['upsell_confirmation']} orders**";
                    }
                    $note = $bits ? ' – ' . implode(', ', $bits) . '.' : '.';
                    $md[] = "* **{$shift->display_name}:** ₱" . number_format($row['upsell_sales'], 0) . " sales | {$row['upsell_confirmation']} orders{$note}";
                }
            }
        }

        // ---- Lead Capacity & Distribution ------------------------------
        $md[] = '### *Lead Capacity & Distribution*';
        $isOpeningShift = fn ($s) => $s->shift_start && (int) date('G', strtotime($s->shift_start)) < 15;
        $openingCapacity = $shifts->filter($isOpeningShift)->count() * self::TARGET_CATERED_LEADS;
        $closingCapacity = $shifts->filter(fn ($s) => !$isOpeningShift($s))->filter(fn ($s) => $s->shift_start)->count() * self::TARGET_CATERED_LEADS;
        $totalCapacity = $openingCapacity + $closingCapacity;

        // ProductPerformance::countedOrdersFor() — the exact distinct-order
        // set $refRow['total'] was summed from (product-matched AND
        // exclusion-applied, same as tally()'s own 'total'). Bucketing
        // $refOrders directly (or intersecting the separately-team-scoped
        // $activityOrders by ID) both under/over-counted in different ways
        // and left Opening+Closing NOT summing back to the total stated
        // above (confirmed live: 202+214=416 leads bucketed vs. 227 in the
        // "incoming leads" line for the same day/team) — see that method's
        // own doc comment for the full reasoning.
        $countedOrders = ProductPerformance::countedOrdersFor($products, $refOrders);
        $byHour = $countedOrders->groupBy(fn (Order $o) => (int) $o->pancake_created_at->format('G'));
        $openingLeads = $byHour->filter(fn ($orders, $h) => $h >= 6 && $h < 15)->flatten(1)->count();
        $closingLeads = $byHour->filter(fn ($orders, $h) => $h >= 15 || $h < 6)->flatten(1)->count();
        $openingOver = max(0, $openingLeads - $openingCapacity);
        $closingOver = max(0, $closingLeads - $closingCapacity);

        $md[] = "Umabot sa **{$refRow['total']} incoming leads** ang total for the day, habang **{$totalCapacity} leads lang ang theoretical capacity** ng team.";
        $md[] = "* **Opening:** {$openingLeads} leads vs. {$openingCapacity} capacity = **{$openingOver} excess**";
        $md[] = "* **Closing:** {$closingLeads} leads vs. {$closingCapacity} capacity = **{$closingOver} excess**";

        $peakHour = null;
        if ($byHour->isNotEmpty() && $byHour->sum(fn ($o) => $o->count()) > 0) {
            $peakHour = $byHour->sortByDesc(fn ($o) => $o->count())->keys()->first();
            $heavierShift = $closingLeads > $openingLeads ? 'Closing' : 'Opening';
            $md[] = "Mas mataas ang lead pressure sa **{$heavierShift}**, especially during peak hours. Pinakamataas ang volume sa **{$this->formatHourRange($peakHour)} with {$byHour[$peakHour]->count()} leads**.";
        }

        // Per-product excess ranking — same product-matched buildRow() every
        // other product-scoped card in this file already uses.
        $productExcess = $products->map(fn ($p) => ['name' => $p->display_name, 'excess' => ProductPerformance::buildRow($p, $refOrders, $products)['excess']])
            ->filter(fn ($p) => $p['excess'] > 0)
            ->sortByDesc('excess')
            ->values();
        if ($productExcess->isNotEmpty()) {
            $top = $productExcess->first();
            $others = $productExcess->skip(1)->take(3)->map(fn ($p) => "{$p['name']} ({$p['excess']})")->implode(', ');
            $md[] = "May **{$refRow['excess']} recorded excess leads**, at **{$top['name']} ang biggest contributor with {$top['excess']} excess**" . ($others ? ", followed by {$others}." : '.');
        }

        $capacityGap = max(0, $refRow['total'] - $totalCapacity);
        if ($capacityGap !== $refRow['excess']) {
            $md[] = "**Note:** Magkaiba ang **{$capacityGap} theoretical capacity gap** at **{$refRow['excess']} recorded excess leads**. Ang {$capacityGap} ay based sa total capacity, habang ang {$refRow['excess']} ay actual excess na recorded sa lead data.";
        }

        // ---- Conversion Analysis ---------------------------------------
        $md[] = '### *Conversion Analysis*';
        $conversionPct = $refRow['catered'] > 0 ? round($refRow['upsell_confirmation'] / $refRow['catered'] * 100, 1) : 0.0;
        $md[] = "From **{$refRow['catered']} catered leads, nakakuha ng {$refRow['upsell_confirmation']} orders**, equivalent to **" . $this->fmt($conversionPct) . '% conversion**.';
        $neededPct = round(self::TARGET_QTY_ORDERS / self::TARGET_CATERED_LEADS * 100, 1);
        $md[] = 'Para ma-hit ang **' . self::TARGET_QTY_ORDERS . ' orders from ' . self::TARGET_CATERED_LEADS . ' catered leads**, kailangan around **' . $this->fmt($neededPct) . '% conversion**.';
        $md[] = 'So hindi lang volume ng leads ang kailangang tutukan. Kailangan ding ma-improve ang **pick-up, objection handling, product presentation, at closing**.';

        // ---- Main Finding & Action Plan ---------------------------------
        $md[] = '### *Main Finding & Action Plan*';
        $findingBits = [];
        if ($openingOver > 0 || $closingOver > 0) {
            $findingBits[] = 'limited manpower during peak lead hours';
        }
        if ($conversionPct < $neededPct) {
            $findingBits[] = 'low conversion efficiency';
        }
        $md[] = 'Main issue today is **' . ($findingBits ? implode(' + ', $findingBits) : "keeping today's pace consistent") . '**.';
        $md[] = 'Moving forward:';
        $actionBits = ['Better **lead allocation per shift and peak hours**'];
        if ($productExcess->isNotEmpty()) {
            $actionBits[] = "Closely monitor **{$productExcess->first()['name']} excess leads**";
        }
        $actionBits[] = 'Focus on **conversion and pick-up**, hindi lang catered leads';
        $actionBits[] = 'Improve **objection handling and closing**';
        $actionBits[] = 'Identify TSAs na **mataas ang catered leads pero mababa ang conversion**';
        $actionBits[] = 'Coordinate with **QA for targeted coaching**';
        foreach ($actionBits as $bit) {
            $md[] = "* {$bit}";
        }

        // ---- Summary -----------------------------------------------------
        $md[] = '### *Summary*';
        if ($openingOver > 0 || $closingOver > 0) {
            $heavierShift = $closingOver > $openingOver ? 'Closing' : 'Opening';
            $heavierExcess = max($openingOver, $closingOver);
            $bothOver = $openingOver > 0 && $closingOver > 0;
            $md[] = "**Mataas ang incoming lead volume pero limited ang team capacity**, kaya " . ($bothOver ? 'parehong shifts nagkaroon ng excess' : "ang {$heavierShift} shift ang nagkaroon ng excess") . ", with **{$heavierShift} having the heavier pressure at {$heavierExcess} excess leads**.";
        } else {
            $md[] = "**Nasa loob ng theoretical capacity** ang parehong shifts ngayong araw — ang excess leads na naitala ({$refRow['excess']}) ay galing sa ibang dahilan (hal. unmatched disposition), hindi sa kakulangan ng manpower.";
        }
        if ($productExcess->isNotEmpty()) {
            $top = $productExcess->first();
            $md[] = "**{$top['name']} ang biggest bottleneck with {$top['excess']} excess leads**" . ($peakHour !== null ? ", while **{$this->formatHourRange($peakHour)}** ang highest hourly volume with **{$byHour[$peakHour]->count()} leads**." : '.');
        }
        $orderVerb = $orderDelta > 0 ? 'Nag-improve' : ($orderDelta < 0 ? 'Bumaba' : 'Flat lang');
        $md[] = "{$orderVerb} ang orders to **{$refRow['upsell_confirmation']}**" . ($refRow['pick_up_rate'] !== null ? ' at ang pick-up rate ay nasa **' . $this->fmt($refRow['pick_up_rate']) . '%**' : '') . ", pero priority pa rin ang mas mataas na **AOV** at **conversion** para sa mas magandang resulta.";
        $md[] = '**Priority moving forward: better lead distribution during peak hours + stronger conversion and closing.**';

        $downSignals = ($orderDelta < 0 ? 1 : 0) + (($refRow['pick_up_rate'] ?? 0) < ($prevRow['pick_up_rate'] ?? 0) ? 1 : 0);
        $severity = $downSignals >= 2 ? 'warning' : 'info';

        return $this->card($severity, 'Overview', '📝', implode("\n\n", $md));
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
