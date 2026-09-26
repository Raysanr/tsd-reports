<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ProjectionColumn;
use App\Models\ProjectionCustomRow;
use App\Models\Setting;
use App\Support\ProjectionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-23: "create
 * new module TSD DATA MANAGEMENT... i want you to make all is editable...
 * like this [Google Sheet]... the blue highlight PROJECTIONS"). Replicates
 * that sheet's own live P&L + target-card calculator — see
 * ProjectionCalculator's own doc comment for the full formula chain and
 * how every default value was verified against the real source sheet.
 */
class ProjectionController extends Controller
{
    public function index()
    {
        // Self-heals an empty table (seen in practice: a dev DB reset
        // after this migration already ran, so it never re-inserts its
        // seed rows) — otherwise this page silently renders with zero
        // columns and no error, which is exactly what happened before
        // this call was added. See ProjectionColumn::ensureSeeded()'s own
        // doc comment.
        ProjectionColumn::ensureSeeded();

        $columns = ProjectionColumn::orderBy('sort_order')->get();
        $rates   = ProjectionCalculator::allRates();

        // forAllColumns(), not a per-column map() — Telesales Department /
        // Individual TSA Monthly / Individual TSA Daily are now DERIVED
        // from Opening Shift's own P&L (×2 / ÷6 / ÷6÷24 — see
        // ProjectionCalculator's own doc comment for why), so they can't
        // be computed one at a time in isolation anymore.
        $computed = collect(ProjectionCalculator::forAllColumns($columns, $rates))
            ->sortBy(fn ($entry) => $entry['column']->sort_order)
            ->values();

        return view('data.projections', [
            'computed' => $computed,
            'rates'    => $rates,
        ]);
    }

    /** Auto-save (explicit request, 2026-09-23: "auto-save as you type") —
     *  one field at a time, same debounced-PATCH-per-field convention as
     *  every other inline-editable field in this app (e.g. TSA Management's
     *  own daily_lead_cap field). Returns the freshly recomputed figures
     *  for the SAME column, so the frontend can update on-screen totals
     *  without a full page reload. */
    public function updateColumn(Request $request, ProjectionColumn $projectionColumn)
    {
        // Parameter MUST be named $projectionColumn, matching the route's
        // {projectionColumn} segment exactly — implicit route-model binding
        // resolves by parameter name, and a mismatched name (this used to
        // be $column) silently binds null instead of erroring, which
        // TypeErrors deep inside ProjectionCalculator::forColumn() instead
        // of at the actual point of failure.
        $column = $projectionColumn;
        $data = $request->validate([
            'net_income_target'   => ['sometimes', 'numeric', 'min:0'],
            'average_order_value' => ['sometimes', 'numeric', 'min:0'],
            // Nullable so clearing the field back to blank restores the
            // target-derived # of Orders instead of pinning it at 0 — see
            // the add_orders_override migration's own doc comment.
            'orders_override'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Same nullable-override convention, for Target Upselling Rate
            // — see the add_upselling_rate_override migration's own doc
            // comment.
            'upselling_rate_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tsa_count'            => ['sometimes', 'integer', 'min:1'],
            'label'                => ['sometimes', 'string', 'max:255'],
        ]);

        $column->update($data);

        $rates   = ProjectionCalculator::allRates();
        // Every column, not just this one — editing Opening Shift's own
        // orders_override/net_income_target/average_order_value cascades
        // into the 3 derived cards (×2/÷6/÷24), so the frontend needs all
        // 4 columns' fresh figures back, the same as a rate edit already
        // returns. See ProjectionCalculator's own doc comment.
        $columns = ProjectionColumn::orderBy('sort_order')->get();
        $all     = ProjectionCalculator::forAllColumns($columns, $rates);

        return response()->json([
            'success'  => true,
            'computed' => $all[$column->key] ?? null,
            'all'      => array_values($all),
        ]);
    }

    /** Same auto-save convention as updateColumn() above, for the shared
     *  rate settings instead — one PATCH per rate field, not a bulk form
     *  submit, so an admin editing several rates in a row never risks one
     *  slow field's request clobbering another's already-saved value the
     *  way a single "save everything" submit could if two requests raced.
     *  Recomputes and returns EVERY column's figures, since a shared rate
     *  changing affects all of them at once. */
    public function updateRates(Request $request)
    {
        // Whitelist includes every custom row's own key too (not just
        // DEFAULT_RATES) — a custom row's rate lives in Settings exactly
        // like a built-in one, see ProjectionCalculator::allRates()'s own
        // doc comment, so its PATCH has to pass this same validation.
        $data = $request->validate([
            'key'   => ['required', 'string', 'in:' . implode(',', array_keys(ProjectionCalculator::allRates()))],
            'value' => ['required', 'numeric'],
        ]);

        Setting::set("projection_rate.{$data['key']}", (float) $data['value']);

        $rates   = ProjectionCalculator::allRates();
        $columns = ProjectionColumn::orderBy('sort_order')->get();

        return response()->json([
            'success'  => true,
            'rates'    => $rates,
            'computed' => array_values(ProjectionCalculator::forAllColumns($columns, $rates)),
        ]);
    }

    /** Adds one custom row (explicit request, 2026-09-26: "add like + icon
     *  on Selling And Marketing and Operating Costs ... has modal ... if
     *  it is editable or fixed") — the + icon lives ONLY on Opening/Closing
     *  Shift's own view, but the row it creates is shared: every column's
     *  own P&L picks it up immediately via ProjectionCalculator::
     *  sellingCostRows()/operatingCostRows(), same as a built-in row.
     *  $initialValue is a dollar amount scoped to the column that added
     *  it, back-solved into the shared rate the same way every other
     *  dollar-mode row input already works (see pj.js's own saveRate()) —
     *  sent from THIS same request rather than a separate PATCH so the row
     *  never renders with a misleading 0.00 for even one page load. */
    public function storeCustomRow(Request $request)
    {
        $data = $request->validate([
            'section'        => ['required', 'string', 'in:selling,operating'],
            'label'          => ['required', 'string', 'max:255'],
            'is_fixed'       => ['sometimes', 'boolean'],
            'initial_value'  => ['sometimes', 'numeric', 'min:0'],
            'gross_sales'    => ['required_with:initial_value', 'numeric', 'min:0'],
        ]);

        $slug = Str::slug($data['label'], '_');
        $key  = 'custom_' . $slug;
        // Uniqueness guard — two rows named the same thing (or names that
        // collide once slugified, e.g. "Ad-Hoc" and "Ad Hoc") would
        // otherwise silently share one Settings rate under the hood.
        $suffix = 1;
        while (ProjectionCustomRow::where('key', $key)->exists()) {
            $key = 'custom_' . $slug . '_' . (++$suffix);
        }

        $row = ProjectionCustomRow::create([
            'key'        => $key,
            'section'    => $data['section'],
            'label'      => $data['label'],
            'is_fixed'   => $data['is_fixed'] ?? false,
            'sort_order' => ProjectionCustomRow::where('section', $data['section'])->max('sort_order') + 1,
        ]);

        if (($data['initial_value'] ?? 0) > 0 && ($data['gross_sales'] ?? 0) > 0) {
            Setting::set("projection_rate.{$key}", $data['initial_value'] / $data['gross_sales']);
        }

        $rates   = ProjectionCalculator::allRates();
        $columns = ProjectionColumn::orderBy('sort_order')->get();

        return response()->json([
            'success'  => true,
            'row'      => $row,
            'rates'    => $rates,
            'computed' => array_values(ProjectionCalculator::forAllColumns($columns, $rates)),
        ]);
    }

    /** Removes one custom row everywhere at once (every column stops
     *  showing it immediately, same as it appeared everywhere the moment
     *  it was added) — also clears its Settings rate so a later row that
     *  happens to slugify to the same key never inherits a stale value. */
    public function destroyCustomRow(ProjectionCustomRow $projectionCustomRow)
    {
        Setting::where('key', "projection_rate.{$projectionCustomRow->key}")->delete();
        $projectionCustomRow->delete();

        $rates   = ProjectionCalculator::allRates();
        $columns = ProjectionColumn::orderBy('sort_order')->get();

        return response()->json([
            'success'  => true,
            'rates'    => $rates,
            'computed' => array_values(ProjectionCalculator::forAllColumns($columns, $rates)),
        ]);
    }
}
