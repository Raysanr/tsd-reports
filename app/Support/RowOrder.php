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
 * holds one row per (row_key, section), built-in or custom, and is the
 * ONLY thing that determines display order from here on — a custom row's
 * own now-legacy ProjectionCustomRow.sort_order column is never read for
 * ordering again, only its id/label/is_fixed/section still are.
 */
class RowOrder
{
    /** Builds the final, ordered key => label list for one section,
     *  reconciling two lists that may not share every key (Expected
     *  Income's own SELLING_COST_ROWS lacks a couple of built-ins
     *  ProjectionCalculator's own copy has, or vice versa) — a row only
     *  the CALLER's own $builtinRows carries is kept; one only present in
     *  row_sort_orders from the OTHER page is silently skipped, never
     *  rendered somewhere it has no real column for.
     *
     *  $builtinRows: key => label, from the caller's own SELLING_COST_ROWS/
     *  OPERATING_COST_ROWS constant. $section: 'selling' or 'operating'. */
    public static function rows(array $builtinRows, string $section): array
    {
        self::ensureSeeded($builtinRows, $section);

        $customRows = ProjectionCustomRow::where('section', $section)->get()->keyBy('key');
        $allKeys = array_unique(array_merge(array_keys($builtinRows), $customRows->keys()->all()));

        $orderByKey = RowSortOrder::where('section', $section)
            ->whereIn('row_key', $allKeys)
            ->pluck('sort_order', 'row_key');

        return collect($allKeys)
            // A key with no row_sort_orders entry yet (shouldn't normally
            // happen once ensureSeeded() has run, but a request racing a
            // fresh custom-row insert could still see this) sorts last
            // rather than erroring.
            ->sortBy(fn ($key) => $orderByKey->get($key, PHP_INT_MAX))
            ->mapWithKeys(fn ($key) => [$key => $builtinRows[$key] ?? $customRows->get($key)?->label])
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
     *  inserts rows that don't already exist. */
    private static function ensureSeeded(array $builtinRows, string $section): void
    {
        $existingKeys = RowSortOrder::where('section', $section)->pluck('row_key')->all();
        $missingBuiltins = array_values(array_diff(array_keys($builtinRows), $existingKeys));

        $customKeysInSection = ProjectionCustomRow::where('section', $section)->pluck('key')->all();
        $missingCustom = array_values(array_diff($customKeysInSection, $existingKeys));

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
            ->when($existingKeys !== [], fn (Collection $c) => $c->values()->map(function ($row, $i) use ($nextOrder) {
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

    /** Moves $rowKey to sit immediately after $afterRowKey (null = move to
     *  the very start of the section) within $section, renumbering only
     *  as needed — used by the drag-and-drop reorder endpoint. Both pages
     *  share this, so a drag on Projections instantly reorders Expected
     *  Income too, same "one shared order everywhere" decision as the
     *  rest of this feature. */
    public static function moveAfter(string $rowKey, ?string $afterRowKey, string $section): void
    {
        $rows = RowSortOrder::where('section', $section)->orderBy('sort_order')->get();
        $without = $rows->reject(fn (RowSortOrder $r) => $r->row_key === $rowKey)->values();

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
        $moved = $rows->first(fn (RowSortOrder $r) => $r->row_key === $rowKey);
        if (!$moved) {
            return;
        }
        $reordered->splice($insertAt, 0, [$moved]);

        foreach ($reordered->values() as $i => $row) {
            if ($row->sort_order !== $i * 10) {
                $row->update(['sort_order' => $i * 10]);
            }
        }
    }
}
