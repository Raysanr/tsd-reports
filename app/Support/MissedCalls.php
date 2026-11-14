<?php

namespace App\Support;

use App\Models\CallEvent;
use App\Models\Lead;
use Illuminate\Support\Collection;

/**
 * Missed-call callback queue (2026-11-14). A customer who calls a TSA's
 * phone and isn't picked up shows up as a 'missed' call event (MacroDroid).
 * It stays in the queue until someone calls that number back (an outgoing
 * call event, which click-to-call also records) or the customer gets
 * through (an 'incoming' event), whichever TSA it is.
 *
 * Only numbers that belong to a lead are listed (personal calls on the
 * phone never show), and only the last DAYS days count.
 */
class MissedCalls
{
    public const DAYS = 3;

    /**
     * Unreturned missed calls, newest first, one per lead:
     * [lead, tsa_id (whose phone rang), missed_at, count].
     * $tsaId limits it to calls that rang on that TSA's phone.
     */
    public static function pending(?int $tsaId = null): Collection
    {
        $since = now()->subDays(self::DAYS);

        $missed = CallEvent::where('direction', 'missed')
            ->where('occurred_at', '>=', $since)
            ->when($tsaId !== null, fn ($q) => $q->where('tsa_id', $tsaId))
            ->orderBy('occurred_at')
            ->get(['tsa_id', 'lead_id', 'phone_number', 'occurred_at'])
            ->groupBy(fn (CallEvent $e) => CallEvent::normalizePhone($e->phone_number))
            ->forget('');

        if ($missed->isEmpty()) {
            return collect();
        }

        $phones = $missed->keys()->filter()->values();

        $lastContact = CallEvent::whereIn('direction', ['outgoing', 'incoming'])
            ->where('occurred_at', '>=', $since)
            ->where(fn ($q) => self::matchingPhones($q, $phones))
            ->get(['phone_number', 'occurred_at'])
            ->groupBy(fn (CallEvent $e) => CallEvent::normalizePhone($e->phone_number))
            ->map(fn (Collection $events) => $events->max('occurred_at'));

        $leads = self::leadsByPhone($phones, $missed->collapse()->pluck('lead_id')->filter()->unique());

        return $missed
            ->map(function (Collection $events, string $phone) use ($lastContact, $leads) {
                $latest = $events->last();
                $lead = $leads->get($phone);
                if ($lead === null) {
                    return null; // not a customer number
                }
                $contact = collect([$lastContact->get($phone), $lead->dialed_at])->filter()->max();
                if ($contact !== null && $contact->gte($latest->occurred_at)) {
                    return null; // called back or got through since
                }

                return [
                    'lead'      => $lead,
                    'tsa_id'    => $latest->tsa_id,
                    'missed_at' => $latest->occurred_at,
                    'count'     => $events->filter(fn (CallEvent $e) => $contact === null || $e->occurred_at->gt($contact))->count(),
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['missed_at']->getTimestamp())
            ->values();
    }

    /** Lead ids with an unreturned missed call, newest first. */
    public static function leadIds(?int $tsaId = null): array
    {
        return self::pending($tsaId)->map(fn (array $row) => $row['lead']->id)->unique()->values()->all();
    }

    /** OR-matches phone_number against these normalized (last-10-digit)
     *  numbers, whatever spacing or dashes the stored number has. */
    private static function matchingPhones($query, Collection $phones)
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        foreach ($phones as $phone) {
            $query->orWhereRaw("{$digits} LIKE ?", ['%' . $phone]);
        }

        return $query;
    }

    /** The customer's latest lead for each normalized phone. */
    private static function leadsByPhone(Collection $phones, Collection $eventLeadIds): Collection
    {
        return Lead::with(['product', 'tsa'])
            ->where(function ($q) use ($phones, $eventLeadIds) {
                $q->whereIn('id', $eventLeadIds->all());
                self::matchingPhones($q, $phones);
            })
            ->get()
            ->groupBy(fn (Lead $l) => CallEvent::normalizePhone($l->phone_number))
            ->map(fn (Collection $g) => $g->sortByDesc(fn (Lead $l) => $l->pancake_created_at?->getTimestamp() ?? 0)->first());
    }
}
