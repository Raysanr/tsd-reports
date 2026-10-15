<?php

namespace App\Support;

use App\Models\ProjectionCustomRow;
use App\Models\RowSortOrder;
use Illuminate\Support\Collection;

/**
 * TSD Data Management — Projections / Expected Income (explicit request,
 * 2026-10-02: "can you make the row can be draggable and can change the
 * position by other row", confirmed: EVERY row — built-in (Salaries, COD
 * Fee, Advertising Cost, ...) AND custom — with ONE shared order across
 * both pages, since they already read the exact same row keys/labels
 * (ProjectionCalculator's own SELLING_COST_ROWS/OPERATING_COST_ROWS,
 * mirrored by ExpectedIncomeCalculator's own near-identical copy — see
 * mergedSellingRows()/mergedOperatingRows() below for the one place that
 * difference is reconciled).
 *
 * See create_row_sort_orders_table's own doc comment for why this is a
 * SEPARATE table from projection_custom_rows (that table is read in 10
 * other files that all assume "a row in it = a real user-added custom
 * row" — folding built-ins in there too would mean auditing every one of
 * those call sites against a flag they don't expect). RowSortOrder instead
 * holds one row per row_key — built-in or custom — and (as of 2026-10-06)
 * is the ONLY thing that determines BOTH display order AND which section
 * ('selling' or 'operating') a row currently belongs to. A custom row's
 * own now-legacy ProjectionCustomRow.section/sort_order columns are never
 * read for this again (same "lazily superseded, left alone" treatment the
 * 2026-10-02 migration already gave sort_order) — only its id/label/
 * is_fixed still are.
 *
 * 2026-10-06 (explicit request: "is it possible that row in the Selling
 * And Marketing can change like it can drag to Operating Costs rows ...
 * vise versa"): previously a row's section was fixed forever — a built-in
 * by whichever PHP constant (SELLING_COST_ROWS vs OPERATING_COST_ROWS) it
 * was hardcoded into, a custom row by its own ProjectionCustomRow.section
 * at creation time — and row_sort_orders.section only ever matched that
 * original assignment, since moveAfter() below never changed it. Now
 * row_sort_orders.section is authoritative and CAN change via drag (see
 * moveAfter()'s own doc comment) — a row dragged into the other section
 * now actually counts toward that section's own Total (Total Selling
 * Costs vs Total Operating Costs), cascading into Net Income, on BOTH
 * Projections and Expected Income (same shared order as always). COD Fee
 * and Fulfillment Fee are the one exception — see this class's own
 * LOCKED_TO_SELLING constant for why they can't safely follow this rule.
 */
class RowOrder
{
    /** Builds the final, ordered key => label list for one section. Every
     *  known key — every built-in from BOTH $builtinRows arguments below,
     *  across both calculators, plus every custom row regardless of its
     *  own ProjectionCustomRow.section — is a CANDIDATE; row_sort_orders'
     *  own 'section' column (not $builtinRows' key membership, not
     *  ProjectionCustomRow.section) is what actually decides which of the
     *  two sections a row currently renders under, so a dragged row shows
     *  up here correctly regardless of where it started.
     *
     *  $builtinRows: key => label, from the CALLING calculator's own
     *  SELLING_COST_ROWS/OPERATING_COST_ROWS constant for $section (used
     *  for this section's own labels) — $otherBuiltinRows is the same
     *  calculator's OTHER constant, needed only so a built-in key currently
     *  dragged INTO $section still has a label to render (its own
     *  constant lives in the other array). $section: 'selling' or
     *  'operating'. */
    public static function rows(array $builtinRows, string $section, array $otherBuiltinRows = []): array
    {
        self::ensureSeeded($builtinRows, $section);
        self::ensureSeeded($otherBuiltinRows, $section === 'selling' ? 'operating' : 'selling');

        $customRows = ProjectionCustomRow::get()->keyBy('key');
        $allBuiltins = $builtinRows + $otherBuiltinRows;
        $allKeys = array_unique(array_merge(array_keys($allBuiltins), $customRows->keys()->all()));

        // The only place that now decides section membership AND order —
        // see this class's own doc comment for why $builtinRows'/
        // $otherBuiltinRows' key lists and ProjectionCustomRow.section are
        // no longer either. A key legitimately absent here (a request
        // racing a fresh custom-row insert, or ensureSeeded() above not
        // having run for it yet for some reason) is dropped from every
        // section this call — self-heals on the next request once
        // ensureSeeded() catches up, same as before this feature.
        $ordersInSection = RowSortOrder::where('section', $section)
            ->whereIn('row_key', $allKeys)
            ->orderBy('sort_order')
            ->pluck('row_key');

        return $ordersInSection
            ->mapWithKeys(fn ($key) => [$key => $allBuiltins[$key] ?? $customRows->get($key)?->label])
            ->all();
    }

    /** Every custom row's own key, same shape customRowKeys() callers
     *  already expect — kept here so ProjectionCalculator/
     *  ExpectedIncomeCalculator don't need their own direct
     *  ProjectionCustomRow query just to build this list. */
    public static function customRowKeys(): array
    {
        return ProjectionCustomRow::get()->mapWithKeys(fn (ProjectionCustomRow $row) => [$row->key => $row->label])->all();
    }

    /** Seeds row_sort_orders for every key in $builtinRows PLUS every real
     *  custom row in $section that doesn't have an entry yet — a built-in
     *  gets its CURRENT array-index position (× 10, leaving gaps so a
     *  dragged row can be slotted between two without a full renumber
     *  every time); a custom row gets slotted after every already-seeded
     *  row (new custom rows still append to the end, same as before this
     *  feature). Idempotent and safe to call on every read — only ever
     *  inserts rows that don't already exist.
     *
     *  "Doesn't have an entry yet" means ANYWHERE, not just in $section —
     *  2026-10-06 bugfix: a key that crossed sections via moveAfter() (now
     *  living in the OTHER section's own row_sort_orders entry) must never
     *  be treated as "missing from $section" and reseeded there too, or
     *  every cross-section move would get silently undone on the very next
     *  read that happens to call ensureSeeded() for its origin section
     *  again (confirmed live while testing this feature: 'rent' moved into
     *  'selling' immediately reappeared in 'operating' the next time
     *  operatingCostRows() ran, since its own ensureSeeded(OPERATING_COST_ROWS,
     *  'operating') call saw no 'rent' row scoped to 'operating' and
     *  recreated one, racing the real move). */
    private static function ensureSeeded(array $builtinRows, string $section): void
    {
        $allExistingKeys = RowSortOrder::pluck('row_key')->all();
        $existingKeysInSection = RowSortOrder::where('section', $section)->pluck('row_key')->all();
        $missingBuiltins = array_values(array_diff(array_keys($builtinRows), $allExistingKeys));

        $customKeysInSection = ProjectionCustomRow::where('section', $section)->pluck('key')->all();
        $missingCustom = array_values(array_diff($customKeysInSection, $allExistingKeys));

        if (empty($missingBuiltins) && empty($missingCustom)) {
            return;
        }

        $nextOrder = (RowSortOrder::where('section', $section)->max('sort_order') ?? -10) + 10;

        $rows = collect($missingBuiltins)
            ->values()
            ->map(fn ($key, $i) => ['row_key' => $key, 'section' => $section, 'sort_order' => $i * 10, 'created_at' => now(), 'updated_at' => now()])
            // Built-ins only seed their OWN gap-free 0,10,20... sequence
            // when the section has no rows at all yet (a brand-new
            // install) — once anything exists, a missing built-in (e.g. a
            // row added to the PHP constant after this table already had
            // data) appends after everything else instead of colliding
            // with already-chosen positions.
            ->when($existingKeysInSection !== [], fn (Collection $c) => $c->values()->map(function ($row, $i) use ($nextOrder) {
                $row['sort_order'] = $nextOrder + $i * 10;
                return $row;
            }))
            ->concat(collect($missingCustom)->values()->map(fn ($key, $i) => [
                'row_key' => $key, 'section' => $section,
                'sort_order' => $nextOrder + (count($missingBuiltins) + $i) * 10,
                'created_at' => now(), 'updated_at' => now(),
            ]))
            ->all();

        if (!empty($rows)) {
            RowSortOrder::insertOrIgnore($rows);
        }
    }

    /** Row keys that must never leave Selling & Marketing via drag, even
     *  though every other row (built-in or custom) now can (2026-10-06).
     *  cod_fee/fulfillment_fee are NOT plain %-of-Gross-Sales rows — their
     *  dollar VALUE is a hardcoded formula (COD Fee = Delivered Sales ×
     *  2.24%, Fulfillment Fee = Orders × ₱25) that both
     *  ProjectionCalculator::pnlFromOrders() and
     *  ExpectedIncomeCalculator::derive() always write into their own
     *  $sellingLines, regardless of what sellingCostRows()/
     *  operatingCostRows() say — letting them report into 'operating' here
     *  would double-count them (summed into Total Operating Costs via
     *  operatingCostRows() now including the key, AND into Total Selling
     *  Costs via that hardcoded write) rather than actually moving. Read
     *  by both calculators (ProjectionCalculator::LOCKED_TO_SELLING/
     *  ExpectedIncomeCalculator::LOCKED_TO_SELLING delegate here) so the
     *  list is defined in exactly one place. */
    public const LOCKED_TO_SELLING = ['cod_fee', 'fulfillment_fee'];

    /** Moves $rowKey to sit immediately after $afterRowKey (null = move to
     *  the very start) within $section, renumbering only as needed — used
     *  by the drag-and-drop reorder endpoint. Both pages share this, so a
     *  drag on Projections instantly reorders Expected Income too, same
     *  "one shared order everywhere" decision as the rest of this feature.
     *
     *  2026-10-06: $section is no longer assumed to already be where
     *  $rowKey lives — a drop target in the OTHER section now genuinely
     *  moves the row there (its row_sort_orders.section is updated to
     *  match, and the row it's leaving renumbers its own remaining rows
     *  to close the gap), unless $rowKey is in LOCKED_TO_SELLING, in which
     *  case a cross-section move is silently ignored (ONLY reordered
     *  within its current section, same as this method's original
     *  same-section-only behavior) — the frontend never shows these 2 rows
     *  a drag handle outside Selling in the first place (see
     *  projections.blade.php's own doc comment), so this is a defense-in-
     *  depth guard against a stale client/replayed request, not the
     *  primary enforcement. */
    public static function moveAfter(string $rowKey, ?string $afterRowKey, string $section): void
    {
        $moved = RowSortOrder::where('row_key', $rowKey)->first();
        if (!$moved) {
            return;
        }

        $fromSection = $moved->section;
        if (in_array($rowKey, self::LOCKED_TO_SELLING, true)) {
            $section = $fromSection; // ignore the requested cross-section move — see this method's own doc comment.
        }
        $crossingSections = $fromSection !== $section;

        $rows = RowSortOrder::where('section', $section)->orderBy('sort_order')->get();
        $without = $crossingSections ? $rows : $rows->reject(fn (RowSortOrder $r) => $r->row_key === $rowKey)->values();

        // Collection::search() returns false (not null) on no match, so a
        // stale/wrong-section $afterRowKey (a row deleted mid-drag, or a
        // client bug) must be checked with === false explicitly — a bare
        // ?? here would never catch it (false ?? x evaluates to false, not
        // x), silently inserting at position 0+1=1 instead of falling back
        // to the end of the list.
        $afterIndex = $afterRowKey === null ? false : $without->search(fn (RowSortOrder $r) => $r->row_key === $afterRowKey);
        $insertAt = $afterIndex === false
            ? ($afterRowKey === null ? 0 : $without->count())
            : $afterIndex + 1;

        $reordered = $without->values();
        $reordered->splice($insertAt, 0, [$moved]);

        foreach ($reordered->values() as $i => $row) {
            $changes = [];
            if ($row->sort_order !== $i * 10) {
                $changes['sort_order'] = $i * 10;
            }
            if ($row->row_key === $rowKey && $crossingSections) {
                $changes['section'] = $section;
            }
            if ($changes) {
                $row->update($changes);
            }
        }

        // The section $rowKey just left now has a gap where it used to
        // sit — close it the same way every other reorder already
        // renumbers (0,10,20...), so a later moveAfter() in THAT section
        // doesn't inherit a stale, gappy sequence.
        if ($crossingSections) {
            $remaining = RowSortOrder::where('section', $fromSection)->orderBy('sort_order')->get();
            foreach ($remaining->values() as $i => $row) {
                if ($row->sort_order !== $i * 10) {
                    $row->update(['sort_order' => $i * 10]);
                }
            }
        }
    }
}
