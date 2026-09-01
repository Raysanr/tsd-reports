<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Turns Pancake's raw order 'histories' (a field-level diff log — old/new
 * pairs per changed field, editor_id, timestamp) into readable sentences
 * matching Pancake POS's own order-history popup (explicit request: "can
 * you fetch the history from pos?" — the local LeadActivity log this app
 * kept before was its own much sparser record of only what THIS app did,
 * not everything that happened to the order in Pancake, including edits
 * made directly in POS by other staff or the API).
 *
 * Confirmed live against real order responses (php artisan tinker, three
 * different orders) rather than guessed from documentation — Pancake's own
 * rendered API docs are a JS app WebFetch can't execute, and the raw
 * OpenAPI spec doesn't include example values, so the field vocabulary and
 * shapes here are drawn directly from what real orders actually contain.
 *
 * One PancakeOrderHistoryFormatter::format() call returns one row per
 * history ENTRY (a single save in Pancake, which can touch multiple
 * fields at once — e.g. deleting one item and adding its replacement is
 * two array entries within one 'items' diff, one row here), each with a
 * list of human sentences, an editor name, and a timestamp — same shape
 * the Detail tab in the lead modal renders directly.
 */
class PancakeOrderHistoryFormatter
{
    /**
     * Fields intentionally never rendered as their own line — bookkeeping
     * side effects that ride along on almost every real save (confirmed
     * across three real orders: amount_owed, bank_payments, surcharges,
     * is_reward_point, is_customer_level, time_assign_seller, and similar
     * always appear alongside a more meaningful field like tags/items/
     * shipping_address on the SAME entry) rather than being a change
     * anyone asked to see. Surfacing them individually would read as noise
     * — an entry with only these fields (no meaningful field changed)
     * simply produces zero sentences and is dropped entirely.
     */
    private const IGNORED_FIELDS = [
        'amount_owed', 'amount_owed_to_customer', 'bank_payments', 'surcharges',
        'is_reward_point', 'is_customer_level', 'time_assign_seller',
        'fee_marketplace', 'account', 'customer_needs',
    ];

    /**
     * @param array $liveOrder The array PancakeOrderTagApi::getOrderDetail()
     *   returns — needs histories, status_history, creator, last_editor,
     *   assigning_seller.
     * @return Collection<int, array{sentences: string[], editor_name: string, editor_avatar: ?string, updated_at: string}>
     *   Newest first, matching Pancake's own popup order.
     */
    public static function format(array $liveOrder): Collection
    {
        $editorNames = self::buildEditorDirectory($liveOrder);

        $rows = collect($liveOrder['histories'] ?? [])
            ->map(function (array $entry) use ($editorNames) {
                $sentences = self::sentencesForEntry($entry, $editorNames);
                if (empty($sentences)) {
                    return null;
                }

                $editorId = $entry['editor_id'] ?? null;
                return [
                    'sentences'     => $sentences,
                    'editor_name'   => $editorNames[$editorId]['name'] ?? 'API',
                    'editor_avatar' => $editorNames[$editorId]['avatar_url'] ?? null,
                    'updated_at'    => $entry['updated_at'] ?? null,
                ];
            })
            ->filter()
            ->values();

        return $rows->sortByDesc('updated_at')->values();
    }

    /**
     * id => ['name' => ..., 'avatar_url' => ...] built from every editor
     * object the order itself exposes (creator/last_editor/
     * assigning_seller, and every status_history entry's own 'editor').
     * 'histories' entries only ever carry a bare editor_id — this is the
     * only way to turn that id into the name Pancake's own popup shows
     * (e.g. "Alden Dimayuga2", "SH Mari"). An id this directory has no
     * entry for is a genuine system/API-key actor confirmed live (no
     * matching editor object exists anywhere on the order for it) —
     * rendered as "API", matching what a real order with that exact
     * situation shows in Pancake's own popup.
     */
    private static function buildEditorDirectory(array $liveOrder): array
    {
        $directory = [];

        foreach (['creator', 'last_editor', 'assigning_seller'] as $key) {
            $editor = $liveOrder[$key] ?? null;
            if (is_array($editor) && !empty($editor['id'])) {
                $directory[$editor['id']] = ['name' => $editor['name'] ?? 'API', 'avatar_url' => $editor['avatar_url'] ?? null];
            }
        }

        foreach ($liveOrder['status_history'] ?? [] as $statusEntry) {
            $editor = $statusEntry['editor'] ?? null;
            if (is_array($editor) && !empty($editor['id'])) {
                $directory[$editor['id']] = ['name' => $editor['name'] ?? 'API', 'avatar_url' => $editor['avatar_url'] ?? $statusEntry['avatar_url'] ?? null];
            }
        }

        return $directory;
    }

    /** @return string[] */
    private static function sentencesForEntry(array $entry, array $editorNames): array
    {
        $sentences = [];

        foreach ($entry as $field => $diff) {
            if (in_array($field, self::IGNORED_FIELDS, true) || in_array($field, ['editor_id', 'updated_at'], true)) {
                continue;
            }
            if (!is_array($diff)) {
                continue;
            }
            // 'items' is the one field whose diff isn't a single {old, new}
            // pair — it's a flat array of per-line-item {variation_id, old,
            // new} entries with no top-level old/new keys of its own
            // (confirmed live: this exact shape is what silently dropped
            // every real item add/delete line from history before this
            // fix — the generic old/new guard below rejected 'items'
            // before itemsSentence() ever got a chance to read it).
            // itemsSentence() validates its own inner shape per element.
            if ($field !== 'items' && (!array_key_exists('old', $diff) || !array_key_exists('new', $diff))) {
                continue;
            }

            $sentence = match ($field) {
                'tags' => self::tagsSentence($diff),
                'items' => self::itemsSentence($diff),
                'status' => self::statusSentence($diff),
                'shipping_address' => self::shippingAddressSentence($diff),
                'note' => self::noteSentence($diff),
                'assigning_seller_id' => self::assigningSellerSentence($diff, $editorNames),
                'order_sources' => self::orderSourceSentence($diff),
                default => null,
            };

            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /** Old/new are always the FULL tag list (confirmed live across 3 real
     *  diffs), not an incremental add/remove — diffed here by id so
     *  "Add tag X, Y" / "Remove tag Z" reads the same way Pancake's own
     *  popup phrases a tag change. */
    private static function tagsSentence(array $diff): ?string
    {
        $old = collect($diff['old'] ?? [])->keyBy('id');
        $new = collect($diff['new'] ?? [])->keyBy('id');

        $added = $new->diffKeys($old)->pluck('name')->filter();
        $removed = $old->diffKeys($new)->pluck('name')->filter();

        if ($added->isEmpty() && $removed->isEmpty()) {
            return null;
        }

        // Confirmed live against Pancake's own real Detail popup: exactly
        // one tag added with none removed reads "Add tag X"; exactly one
        // removed with none added reads "Delete tag X"; anything bigger —
        // several tags added at once, or an add and a remove together in
        // the same save — collapses to "Edit tag from [full old list] into
        // [full new list]" instead of stacking separate Add/Remove lines.
        if ($added->count() === 1 && $removed->isEmpty()) {
            return 'Add tag ' . $added->first();
        }
        if ($removed->count() === 1 && $added->isEmpty()) {
            return 'Delete tag ' . $removed->first();
        }

        $oldNames = $old->pluck('name')->filter()->implode(', ');
        $newNames = $new->pluck('name')->filter()->implode(', ');
        return "Edit tag from {$oldNames} into {$newNames}";
    }

    /** items diff is a flat array of per-line-item {variation_id, old, new}
     *  entries (confirmed live) — a deleted item has new=null, an added
     *  item has old=null; a swap (delete-then-add, e.g. a price/qty edit
     *  via this app's own updateItem()) appears as one of each sharing the
     *  same variation_id, matching the screenshot's paired "Delete
     *  products X" / "Add products X" lines exactly. */
    private static function itemsSentence(array $diff): ?string
    {
        $lines = [];
        foreach ($diff as $itemDiff) {
            if (!is_array($itemDiff)) continue;

            if (($itemDiff['new'] ?? null) === null && isset($itemDiff['old'])) {
                $lines[] = 'Delete products ' . self::describeItem($itemDiff['old']);
            } elseif (($itemDiff['old'] ?? null) === null && isset($itemDiff['new'])) {
                $lines[] = 'Add products ' . self::describeItem($itemDiff['new']);
            }
        }

        return $lines ? implode("\n", $lines) : null;
    }

    private static function describeItem(array $item): string
    {
        $vi = $item['variation_info'] ?? [];
        $name = $vi['name'] ?? '—';
        $weight = $vi['weight'] ?? 0;
        $price = $vi['retail_price'] ?? 0;
        $qty = $item['quantity'] ?? 1;

        return "{$name} / {$weight}g / / ₱" . number_format($price, 0) . " x {$qty}";
    }

    /** Numeric Pancake status codes aren't self-describing without a
     *  lookup table. \App\Models\Order::STATUS_PILL (not the older,
     *  divergent STATUS_LABELS — confirmed live: STATUS_LABELS[20] says
     *  "Purchased", but Pancake's own popup and this app's own visible
     *  Status pill both call it "Ordered") is the one actually driving
     *  Call Tracker's own Status pill elsewhere in this same modal, so
     *  using it here keeps this history's wording consistent with what
     *  the rest of the page already displays for the same code. */
    private static function statusSentence(array $diff): ?string
    {
        $labels = collect(\App\Models\Order::STATUS_PILL)->map(fn ($p) => $p['label']);
        $newLabel = $labels[$diff['new'] ?? null] ?? ('#' . ($diff['new'] ?? '?'));
        $oldLabel = $labels[$diff['old'] ?? null] ?? null;

        return $oldLabel
            ? "Changed order status from \"{$oldLabel}\" to \"{$newLabel}\""
            : "Changed order status to \"{$newLabel}\"";
    }

    private static function shippingAddressSentence(array $diff): ?string
    {
        $old = $diff['old'] ?? null;
        $new = $diff['new'] ?? null;
        if (!$new) return null;

        $describe = fn (?array $addr) => $addr
            ? collect([$addr['full_name'] ?? null, $addr['phone_number'] ?? null, $addr['full_address'] ?? $addr['address'] ?? null])
                ->filter()->implode(' / ')
            : null;

        $newDesc = $describe($new);
        $oldDesc = $describe($old);

        if (!$oldDesc || $oldDesc === $newDesc) {
            return "Edit delivery to {$newDesc}";
        }

        return "Edit delivery from {$oldDesc} into {$newDesc}";
    }

    private static function noteSentence(array $diff): ?string
    {
        // Collapsed to spaces — Pancake's own note text carries literal
        // \r\n line breaks (confirmed live), which read fine in the full
        // multi-line Pancake Notes panel elsewhere in this same modal but
        // would otherwise break this single-line truncated preview across
        // several lines instead of a clean "…"-truncated snippet.
        $clean = fn ($text) => trim(preg_replace('/\s+/', ' ', (string) $text));
        $new = $clean($diff['new'] ?? '');
        $old = $clean($diff['old'] ?? '');
        if ($new === '') return null;

        // Truncated to match Pancake's own popup, which previews only the
        // first portion of a long note inline (confirmed live: both "Add
        // internal note X…" on a genuinely first-time note, and "Edit
        // internal note from X… into Y…" on a note that already had a
        // prior value, each side truncated independently the same way).
        $preview = fn (string $text) => mb_strlen($text) > 24 ? mb_substr($text, 0, 24) . '…' : $text;

        if ($old === '' || $old === $new) {
            return 'Add internal note ' . $preview($new);
        }

        return "Edit internal note from {$preview($old)} into {$preview($new)}";
    }

    private static function assigningSellerSentence(array $diff, array $editorNames): ?string
    {
        $newId = $diff['new'] ?? null;
        if (!$newId) return null;

        $name = $editorNames[$newId]['name'] ?? 'API';
        return "Add Handler {$name}";
    }

    private static function orderSourceSentence(array $diff): ?string
    {
        $new = $diff['new'] ?? null;
        if (!is_array($new) || empty($new)) return null;

        $label = collect($new)->pluck('name')->filter()->implode(', ');
        return $label !== '' ? "Add Order source {$label}" : null;
    }
}