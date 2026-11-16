<?php

namespace App\Support;

use App\Models\CallEvent;
use App\Models\LeadActivity;
use App\Models\LeadCallResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Best time to call (Analytics, 2026-11-14; counted from dials 2026-11-16).
 *
 * Every dial is one call, in the hour it started: Call Tracker's
 * click-to-call / auto-call, and outgoing calls the phone reports
 * (MacroDroid), including ones dialed straight from the phone, matched to
 * the customer's lead by number. Records of the same lead within
 * SAME_DIAL_MINUTES are one call (a click logs it, the phone reports it
 * again when it ends).
 *
 * Answered or not, per dial, in this order:
 * 1. an answered result (FollowUpAnalytics::ANSWERED, Pancake tag history)
 *    recorded after the dial and before the lead's next dial (at most
 *    RESULT_WINDOW_MINUTES later) → answered;
 * 2. a Not answering / Unattended result in that window → unanswered;
 * 3. no result: the phone's connected time — CONNECTED_SECONDS or more →
 *    answered (a TSA who forgot to tag), else unanswered (a repeat "Not
 *    answering" adds no new tag, so tag history alone can't see it).
 * A dial whose only result is Invalid number, DFR, Double order or FSD is
 * left out: it says nothing about picking up.
 *
 * A retry is a dial for a lead that was already unanswered before it.
 * An hour needs MIN_CALLS calls to be named a best hour.
 */
class BestTimeToCall
{
    public const MIN_CALLS = 10;

    private const TOP = 3;

    private const SAME_DIAL_MINUTES = 3;

    private const RESULT_WINDOW_MINUTES = 120;

    /** The phone reports connected time only (0 when nobody picked up); this
     *  much talk means the customer answered even if no new tag was added. */
    private const CONNECTED_SECONDS = 10;

    public static function build(Carbon $from, Carbon $to): array
    {
        $dialsByLead = self::dials($from, $to);
        $resultsByLead = $dialsByLead->isEmpty() ? collect() : LeadCallResult::whereIn('lead_id', $dialsByLead->keys())
            ->where('occurred_at', '<=', $to->copy()->addMinutes(self::RESULT_WINDOW_MINUTES))
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['lead_id', 'result', 'occurred_at'])
            ->groupBy('lead_id');

        $hours = collect(range(0, 23))->mapWithKeys(fn ($h) => [$h => ['calls' => 0, 'answered' => 0, 'retryCalls' => 0, 'retryAnswered' => 0]])->all();

        foreach ($dialsByLead as $leadId => $dials) {
            $results = collect($resultsByLead->get($leadId, []));
            $wasUnanswered = false;

            foreach ($dials as $i => $dial) {
                $dialAt = $dial['at'];
                $windowEnd = $dialAt->copy()->addMinutes(self::RESULT_WINDOW_MINUTES);
                if (isset($dials[$i + 1]) && $dials[$i + 1]['at']->lt($windowEnd)) {
                    $windowEnd = $dials[$i + 1]['at'];
                }
                $isRetry = $wasUnanswered || $results->contains(fn ($r) => $r->occurred_at->lt($dialAt)
                    && LeadCallResults::matches($r->result, FollowUpAnalytics::RETRYABLE));

                $after = $results->filter(fn ($r) => $r->occurred_at->gte($dialAt) && $r->occurred_at->lt($windowEnd));
                $answeredTag = $after->contains(fn ($r) => LeadCallResults::matches($r->result, FollowUpAnalytics::ANSWERED));
                $unansweredTag = $after->contains(fn ($r) => LeadCallResults::matches($r->result, FollowUpAnalytics::RETRYABLE));
                if (! $answeredTag && ! $unansweredTag && $after->isNotEmpty()) {
                    continue; // only Invalid number, DFR, … — says nothing about picking up
                }
                // An answered tag decides; else a Not answering / Unattended tag;
                // else the phone's own call length (connected time) decides.
                $answered = $answeredTag || (! $unansweredTag && $dial['seconds'] >= self::CONNECTED_SECONDS);
                if ($dialAt->lt($from)) {
                    $wasUnanswered = ! $answered; // before the range: retry history only

                    continue;
                }

                $hour = (int) $dialAt->copy()->timezone('Asia/Manila')->format('G');
                $hours[$hour]['calls']++;
                $hours[$hour]['answered'] += $answered ? 1 : 0;
                if ($isRetry) {
                    $hours[$hour]['retryCalls']++;
                    $hours[$hour]['retryAnswered'] += $answered ? 1 : 0;
                }
                $wasUnanswered = ! $answered;
            }
        }

        $rows = collect($hours)->map(fn (array $h, int $hour) => $h + [
            'hour'      => $hour,
            'label'     => HourFormatter::label($hour),
            'rate'      => $h['calls'] > 0 ? round($h['answered'] / $h['calls'] * 100, 1) : null,
            'retryRate' => $h['retryCalls'] > 0 ? round($h['retryAnswered'] / $h['retryCalls'] * 100, 1) : null,
        ])->filter(fn (array $h) => $h['calls'] > 0)->values();

        $calls = $rows->sum('calls');

        return [
            'rows'      => $rows->all(),
            'calls'     => $calls,
            'rate'      => $calls > 0 ? round($rows->sum('answered') / $calls * 100, 1) : null,
            'best'      => self::top($rows, 'calls', 'rate'),
            'bestRetry' => self::top($rows, 'retryCalls', 'retryRate'),
        ];
    }

    /**
     * Each lead's dials, oldest first, near-duplicates merged. Leads dialed in
     * the range, with their earlier dials too (for whether a dial is a retry).
     *
     * Sources: Call Tracker's click-to-call / auto-call ('call_clicked'), the
     * outgoing call events matched to a lead, and outgoing calls the phone
     * reported for a number only (dialed outside Call Tracker) — those go to
     * the customer's latest lead created before the call, as in
     * FollowUpAnalytics. 'seconds' is the longest connected time the phone
     * reported for the dial (null when the phone sent none).
     *
     * @return Collection<int, list<array{at: Carbon, seconds: ?int}>>
     */
    private static function dials(Carbon $from, Carbon $to): Collection
    {
        $since = $from->copy()->subDays(7);

        $entries = LeadActivity::where('type', 'call_clicked')->whereBetween('created_at', [$since, $to])
            ->get(['lead_id', 'created_at'])
            ->map(fn (LeadActivity $a) => ['lead' => (int) $a->lead_id, 'at' => $a->created_at, 'seconds' => null]);

        $events = CallEvent::where('direction', 'outgoing')->whereBetween('occurred_at', [$since, $to])
            ->get(['lead_id', 'phone_number', 'occurred_at', 'duration_seconds']);

        $numberOnly = $events->whereNull('lead_id');
        $leadsByPhone = $numberOnly->isEmpty() ? collect() : \App\Models\Lead::whereBetween('pancake_created_at', [$since->copy()->subDays(7), $to])
            ->whereNotNull('phone_number')
            ->get(['id', 'phone_number', 'pancake_created_at'])
            ->groupBy(fn ($l) => CallEvent::normalizePhone($l->phone_number))
            ->map(fn (Collection $g) => $g->sortByDesc(fn ($l) => $l->pancake_created_at->getTimestamp())->values());

        foreach ($events as $e) {
            $leadId = $e->lead_id;
            if ($leadId === null) {
                $phone = CallEvent::normalizePhone($e->phone_number);
                $leadId = $phone ? collect($leadsByPhone->get($phone, []))
                    ->first(fn ($l) => $l->pancake_created_at->lte($e->occurred_at))?->id : null;
            }
            if ($leadId !== null) {
                // The phone reports a call when it ends; time it from its start.
                $entries->push(['lead' => (int) $leadId, 'at' => $e->occurred_at->copy()->subSeconds((int) $e->duration_seconds), 'seconds' => $e->duration_seconds]);
            }
        }

        // Only leads with a dial inside the range; earlier dials stay as history.
        $inRange = $entries->filter(fn ($d) => $d['at']->gte($from))->pluck('lead')->unique()->flip();

        return $entries->filter(fn ($d) => $inRange->has($d['lead']))
            ->groupBy('lead')
            ->map(function (Collection $rows) {
                $merged = [];
                foreach ($rows->sortBy(fn ($d) => $d['at']->getTimestamp()) as $d) {
                    $last = count($merged) - 1;
                    if ($last >= 0 && $merged[$last]['at']->diffInMinutes($d['at']) < self::SAME_DIAL_MINUTES) {
                        if ($d['seconds'] !== null) {
                            $merged[$last]['seconds'] = max($merged[$last]['seconds'] ?? 0, (int) $d['seconds']);
                        }

                        continue;
                    }
                    $merged[] = ['at' => $d['at'], 'seconds' => $d['seconds'] === null ? null : (int) $d['seconds']];
                }

                return $merged;
            });
    }

    /** The TOP hours by rate among hours with enough calls. */
    private static function top(Collection $rows, string $countKey, string $rateKey): array
    {
        return $rows->filter(fn (array $h) => $h[$countKey] >= self::MIN_CALLS)
            ->sortByDesc(fn (array $h) => [$h[$rateKey], $h[$countKey]])
            ->take(self::TOP)
            ->map(fn (array $h) => ['label' => $h['label'], 'rate' => $h[$rateKey], 'calls' => $h[$countKey]])
            ->values()->all();
    }
}
