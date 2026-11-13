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
 * Unanswered-call follow-up (Analytics, 2026-11-13). For leads that came in
 * during the range and whose FIRST call result logged in Call Tracker was
 * Not Answering or Unattended (the results worth calling again):
 *
 * - called again: a later call result logged in Call Tracker, OR an outgoing
 *   call from a TSA's phone to that customer (call events, matched by lead or
 *   by phone number) after the first result was logged;
 * - answered later: a later logged result in the Answered group;
 * - converted later: a later result of Confirmed via Call, Upsell, Repeat
 *   Order or Relatives Confirmation, or the order became a real upsell.
 *
 * Call results come from LeadActivity 'called' entries ('Logged "<result>"
 * by <name>.'), written each time a TSA logs a result.
 */
class FollowUpAnalytics
{
    /** First results that count as "unanswered, worth calling again". */
    private const RETRYABLE = ['not answering', 'unattended'];

    private const ANSWERED = ['confirmed via call', 'upsell', 'call back', 'call dropped', 'repeat order', 'rude customer', 'relatives'];

    private const CONVERTED = ['confirmed via call', 'upsell', 'repeat order', 'relatives'];

    /** How many never-retried leads to list. */
    private const NEVER_RETRIED_LIMIT = 50;

    public static function build(Carbon $from, Carbon $to): array
    {
        $leads = Lead::whereBetween('pancake_created_at', [$from, $to])
            ->get(['id', 'pancake_order_id', 'customer_name', 'phone_number', 'tsa_id', 'pancake_created_at'])
            ->keyBy('id');

        $attemptsByLead = LeadActivity::where('type', 'called')
            ->whereIn('lead_id', $leads->keys())
            ->orderBy('created_at')->orderBy('id')
            ->get(['lead_id', 'description', 'created_at'])
            ->map(fn (LeadActivity $a) => ['lead_id' => $a->lead_id, 'at' => $a->created_at, 'result' => self::resultOf($a->description)])
            ->filter(fn ($a) => $a['result'] !== null)
            ->groupBy('lead_id');

        $firstUnanswered = $attemptsByLead->filter(fn (Collection $attempts) => self::matches($attempts->first()['result'], self::RETRYABLE));

        // Outgoing phone calls that could be a retry: from the earliest first
        // attempt to a week after the range.
        $phoneCalls = $firstUnanswered->isEmpty() ? collect() : CallEvent::where('direction', 'outgoing')
            ->whereBetween('occurred_at', [$firstUnanswered->min(fn ($a) => $a->first()['at']), $to->copy()->addDays(7)])
            ->get(['lead_id', 'phone_number', 'occurred_at']);
        $callsByLead = $phoneCalls->whereNotNull('lead_id')->groupBy('lead_id');
        $callsByPhone = $phoneCalls->groupBy(fn (CallEvent $e) => CallEvent::normalizePhone($e->phone_number));

        $upsoldOrderIds = Order::whereIn('pancake_order_id', $leads->only($firstUnanswered->keys()->all())->pluck('pancake_order_id'))
            ->get()->filter(fn (Order $o) => Order::isBroadRealUpsell($o))->pluck('pancake_order_id')->flip();

        $rows = $firstUnanswered->map(function (Collection $attempts, $leadId) use ($leads, $callsByLead, $callsByPhone, $upsoldOrderIds) {
            $lead = $leads[$leadId];
            $firstAt = $attempts->first()['at'];
            $later = $attempts->slice(1);

            $phone = CallEvent::normalizePhone($lead->phone_number);
            $laterPhoneCall = collect($callsByLead->get($leadId, []))
                ->merge($phone ? $callsByPhone->get($phone, []) : [])
                ->filter(fn (CallEvent $e) => $e->occurred_at->gt($firstAt))
                ->min(fn (CallEvent $e) => $e->occurred_at->getTimestamp());

            $retryTimes = array_filter([$later->first()['at'] ?? null, $laterPhoneCall ? Carbon::createFromTimestamp($laterPhoneCall) : null]);
            $retryAt = $retryTimes === [] ? null : min($retryTimes);

            return [
                'lead'      => $lead,
                'first_at'  => $firstAt,
                'retried'   => $retryAt !== null,
                'retry_min' => $retryAt ? max(0, $firstAt->diffInMinutes($retryAt)) : null,
                'answered'  => $later->contains(fn ($a) => self::matches($a['result'], self::ANSWERED)),
                'converted' => $later->contains(fn ($a) => self::matches($a['result'], self::CONVERTED)) || isset($upsoldOrderIds[$lead->pancake_order_id]),
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

    /** 'Logged "NOT ANSWERING" by Joana.' -> 'NOT ANSWERING'. */
    private static function resultOf(?string $description): ?string
    {
        return preg_match('/^Logged "(.+)" by /', (string) $description, $m) ? $m[1] : null;
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
