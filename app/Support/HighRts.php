<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Order;

/**
 * HIGH RTS leads are never handed to a TSA automatically (explicit request,
 * 2026-10-26) — they stay Unassigned for a supervisor to decide. Pancake
 * marks these customers with an internal order note "HIGH RTS" (sometimes
 * "JHUN - HIGH RTS") and/or a "HIGH RTS" customer tag.
 */
class HighRts
{
    private const MARK = 'HIGH RTS';

    /** From a raw Pancake order payload: its note or its customer's tags. */
    public static function inRawOrder(array $raw): bool
    {
        if (self::mentions($raw['note'] ?? null)) {
            return true;
        }
        foreach ((array) ($raw['customer']['tags'] ?? []) as $tag) {
            if (self::mentions(is_array($tag) ? ($tag['name'] ?? $tag['text'] ?? null) : $tag)) {
                return true;
            }
        }

        return false;
    }

    /** A lead flagged at sync time, or whose synced order note says HIGH RTS. */
    public static function lead(Lead $lead): bool
    {
        if ($lead->high_rts) {
            return true;
        }
        $note = Order::where('pancake_order_id', $lead->pancake_order_id)->value('note');
        if (self::mentions($note)) {
            $lead->forceFill(['high_rts' => true])->saveQuietly();

            return true;
        }

        return false;
    }

    private static function mentions($text): bool
    {
        return is_string($text) && str_contains(strtoupper($text), self::MARK);
    }
}
