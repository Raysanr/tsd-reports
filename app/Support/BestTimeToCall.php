<?php

namespace App\Support;

use App\Models\LeadCallResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Best time to call (Analytics, 2026-11-14). Answer rate by the hour the
 * call was actually made, from Pancake's exact call-result history
 * (lead_call_results, see LeadCallResults): a result in
 * FollowUpAnalytics::ANSWERED counts as answered, Not Answering /
 * Unattended as unanswered; other results (Invalid number, DFR, …) are
 * left out. "Retry" calls are results for a lead that already had an
 * unanswered result earlier, i.e. the best hours to call back.
 *
 * An hour needs at least MIN_CALLS calls to be named a best hour, so one
 * lucky call at 6 AM can't top the list.
 */
class BestTimeToCall
{
    public const MIN_CALLS = 10;

    private const TOP = 3;

    public static function build(Carbon $from, Carbon $to): array
    {
        $inRange = LeadCallResult::whereBetween('occurred_at', [$from, $to])
            ->get(['lead_id', 'result', 'occurred_at']);

        // Each lead's earlier results too, to know whether a call is a retry.
        $history = $inRange->isEmpty() ? collect() : LeadCallResult::whereIn('lead_id', $inRange->pluck('lead_id')->unique())
            ->where('occurred_at', '<=', $to)
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['lead_id', 'result', 'occurred_at'])
            ->groupBy('lead_id');

        $hours = collect(range(0, 23))->mapWithKeys(fn ($h) => [$h => ['calls' => 0, 'answered' => 0, 'retryCalls' => 0, 'retryAnswered' => 0]])->all();

        foreach ($inRange as $row) {
            $answered = LeadCallResults::matches($row->result, FollowUpAnalytics::ANSWERED);
            if (! $answered && ! LeadCallResults::matches($row->result, FollowUpAnalytics::RETRYABLE)) {
                continue;
            }
            $hour = (int) $row->occurred_at->copy()->timezone('Asia/Manila')->format('G');
            $isRetry = collect($history->get($row->lead_id, []))
                ->contains(fn ($earlier) => $earlier->occurred_at->lt($row->occurred_at)
                    && LeadCallResults::matches($earlier->result, FollowUpAnalytics::RETRYABLE));

            $hours[$hour]['calls']++;
            $hours[$hour]['answered'] += $answered ? 1 : 0;
            if ($isRetry) {
                $hours[$hour]['retryCalls']++;
                $hours[$hour]['retryAnswered'] += $answered ? 1 : 0;
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
