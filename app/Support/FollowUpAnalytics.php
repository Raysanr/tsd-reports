<?php

namespace App\Support;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Unanswered-call follow-up (Analytics, 2026-11-13). Built from what Call
 * Tracker really records (checked on production, 2026-11-13):
 * - each dial: a 'call_clicked' activity and an outgoing call event (lead_id
 *   set), plus phone call events matched by number;
 * - call results: Pancake tags picked up by the lead sync — 'callback_scheduled'
 *   ("Tagged for Unanswered Calls (Pancake tag "Not answering", …)",
 *   "Callback due now (Pancake tag "CALL BACK", …)"), 'tag_added'
 *   ("Added tag "CONFIRMED VIA CALL" by …") and the rare logged Outcome
 *   ('called': "Logged "…" by …"); plus the lead's and order's final result.
 *
 * For leads that came in during the range whose FIRST result was Not
 * Answering or Unattended:
 * - called again: any dial after that result was recorded;
 * - answered later: a later result in the Answered group, or the final
 *   result is in it;
 * - converted later: a later/final result of Confirmed via Call, Upsell,
 *   Repeat Order or Relatives Confirmation, or the order became a real upsell.
 */
class FollowUpAnalytics
{
    /** First results that count as "unanswered, worth calling again". */
    private const RETRYABLE = ['not answering', 'unattended'];

    private const ANSWERED = ['confirmed via call', 'upsell', 'call back', 'call dropped', 'repeat order', 'rude customer', 'relatives'];

    private const CONVERTED = ['confirmed via call', 'upsell', 'repeat order', 'relatives'];

    /** Every call result we recognise (results vs. product/other tags). */
    private const RESULTS = ['not answering', 'unattended', 'invalid number', 'dfr', 'double order', 'fsd',
        'confirmed via call', 'upsell', 'call back', 'call dropped', 'repeat order', 'rude customer', 'relatives'];

    /** How many never-retried leads to list. */
    private const NEVER_RETRIED_LIMIT = 50;

    public static function build(Carbon $from, Carbon $to): array
    {
        $leads = Lead::whereBetween('pancake_created_at', [$from, $to])
            ->get(['id', 'pancake_order_id', 'customer_name', 'phone_number', 'tsa_id', 'disposition', 'pancake_created_at'])
            ->keyBy('id');

        $activities = LeadActivity::whereIn('type', ['called', 'callback_scheduled', 'tag_added', 'call_clicked'])
            ->whereIn('lead_id', $leads->keys())
            ->orderBy('created_at')->orderBy('id')
            ->get(['lead_id', 'type', 'description', 'created_at'])
            ->groupBy('lead_id');

        $results = $activities->map(fn (Collection $rows) => $rows
            ->map(fn (LeadActivity $a) => ['at' => $a->created_at, 'result' => self::resultOf($a)])
            ->filter(fn ($r) => $r['result'] !== null)
            ->values())
            ->filter(fn (Collection $r) => $r->isNotEmpty() && self::matches($r->first()['result'], self::RETRYABLE));

        $dialsFromActivity = $activities->map(fn (Collection $rows) => $rows->where('type', 'call_clicked')->pluck('created_at'));

        $phoneCalls = $results->isEmpty() ? collect() : CallEvent::where('direction', 'outgoing')
            ->whereBetween('occurred_at', [$results->min(fn ($r) => $r->first()['at']), $to->copy()->addDays(7)])
            ->get(['lead_id', 'phone_number', 'occurred_at']);
        $callsByLead = $phoneCalls->whereNotNull('lead_id')->groupBy('lead_id');
        $callsByPhone = $phoneCalls->groupBy(fn (CallEvent $e) => CallEvent::normalizePhone($e->phone_number));

        $orders = Order::whereIn('pancake_order_id', $leads->only($results->keys()->all())->pluck('pancake_order_id'))
            ->get()->keyBy('pancake_order_id');

        $rows = $results->map(function (Collection $leadResults, $leadId) use ($leads, $dialsFromActivity, $callsByLead, $callsByPhone, $orders) {
            $lead = $leads[$leadId];
            $firstAt = $leadResults->first()['at'];
            $laterResults = $leadResults->slice(1)->pluck('result');

            $phone = CallEvent::normalizePhone($lead->phone_number);
            $retryAt = collect($dialsFromActivity->get($leadId, []))
                ->merge(collect($callsByLead->get($leadId, []))->pluck('occurred_at'))
                ->merge($phone ? collect($callsByPhone->get($phone, []))->pluck('occurred_at') : [])
                ->filter(fn ($at) => $at->gt($firstAt))
                ->sort()->first();

            $order = $orders->get($lead->pancake_order_id);
            $final = array_filter([$order?->disposition, $lead->disposition]);
            $finalMatches = fn (array $keywords) => collect($final)->contains(fn ($d) => self::matches($d, $keywords));

            return [
                'lead'      => $lead,
                'first_at'  => $firstAt,
                'retried'   => $retryAt !== null,
                'retry_min' => $retryAt ? $firstAt->diffInMinutes($retryAt) : null,
                'answered'  => $laterResults->contains(fn ($r) => self::matches($r, self::ANSWERED)) || $finalMatches(self::ANSWERED),
                'converted' => $laterResults->contains(fn ($r) => self::matches($r, self::CONVERTED)) || $finalMatches(self::CONVERTED)
                    || ($order && Order::isBroadRealUpsell($order)),
            ];
        })->values();

        $tsaNames = TsaShift::withTrashed()->whereIn('id', $rows->pluck('lead.tsa_id')->filter()->unique())->pluck('display_name', 'id');

        return [
            'totals'       => self::summarize($rows),
            'byTsa'        => $rows->groupBy(fn ($r) => $r['lead']->tsa_id ?? 0)
                ->map(fn (Collection $group, $tsaId) => ['name' => $tsaNames[$tsaId] ?? 'Unassigned'] + self::summarize($group))
                ->sortByDesc('first_unanswered')->values()->all(),
            'neverRetried' => $rows->reject(fn ($r) => $r['retried'])->sortByDesc('first_at')->take(self::NEVER_RETRIED_LIMIT)
                ->map(fn ($r) => [
                    'order_id' => $r['lead']->pancake_order_id,
                    'customer' => $r['lead']->customer_name,
                    'tsa'      => $tsaNames[$r['lead']->tsa_id] ?? 'Unassigned',
                    'first_at' => $r['first_at']->timezone('Asia/Manila')->format('M j, g:i A'),
                ])->values()->all(),
        ];
    }

    private static function summarize(Collection $rows): array
    {
        $count = $rows->count();
        $retried = $rows->where('retried', true);
        $pct = fn (int $n) => $count > 0 ? round($n / $count * 100, 1) : null;

        return [
            'first_unanswered' => $count,
            'retried'          => $retried->count(),
            'retried_pct'      => $pct($retried->count()),
            'answered'         => $rows->where('answered', true)->count(),
            'answered_pct'     => $pct($rows->where('answered', true)->count()),
            'converted'        => $rows->where('converted', true)->count(),
            'converted_pct'    => $pct($rows->where('converted', true)->count()),
            'never_retried'    => $count - $retried->count(),
            'avg_retry_min'    => $retried->isNotEmpty() ? (int) round($retried->avg('retry_min')) : null,
        ];
    }

    /** The call result an activity records, or null (dials, product tags…). */
    private static function resultOf(LeadActivity $activity): ?string
    {
        $text = (string) $activity->description;
        $result = match ($activity->type) {
            'called'             => preg_match('/^Logged "(.+)" by /', $text, $m) ? $m[1] : null,
            'callback_scheduled' => preg_match('/Pancake tag "([^"]+)"/', $text, $m) ? $m[1] : null,
            'tag_added'          => preg_match('/^Added tag "([^"]+)"/', $text, $m) ? $m[1] : null,
            default              => null,
        };

        return $result !== null && self::matches($result, self::RESULTS) ? trim($result) : null;
    }

    private static function matches(string $result, array $keywords): bool
    {
        $result = str_replace("'", '', $result);
        foreach ($keywords as $keyword) {
            if (stripos($result, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
