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
    public function index(Request $request)
    {
        // Same "session, keyed per page" remembered-filter convention as
        // DateRangeFilter/ExpectedIncomeController::resolveSelectedTeam()
        // (explicit request, 2026-10-02: "add (add projection) button ...
        // pop up modal that can select month") — a fresh sidebar-link
        // navigation has no query string of its own, so without this the
        // month filter would silently reset to the current month every
        // time, same bug those two already got fixed for.
        $month = $this->resolveSelectedMonth($request);

        // Self-heals this month's own 7 rows (seen in practice for the
        // single-month version of this table: a dev DB reset after a
        // migration already ran, so it never re-inserts its seed rows —
        // same failure mode, now per-month) — otherwise this page
        // silently renders with zero columns and no error. Also doubles
        // as "Add Projection"'s own real create path: a month with no
        // existing rows gets its blank/default 7 here, a month that
        // already has data is a safe no-op (explicit decisions,
        // 2026-10-02) — see ensureSeededForMonth()'s own doc comment.
        ProjectionColumn::ensureSeededForMonth($month);

        $columns = ProjectionColumn::where('month', $month)->orderBy('sort_order')->get();
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
            'month'    => $month,
            'existingMonths' => ProjectionColumn::existingMonths(),
        ]);
    }

    /** Resolution order: the URL's own ?month= (the user just picked a
     *  month in the picker, submitted "Add Projection", or followed a
     *  link/bookmark) always wins and gets saved to session; otherwise
     *  whatever was last saved; otherwise the current real calendar month
     *  on a brand-new session. An invalid month string falls back to the
     *  current month rather than a broken filter, same defensive pattern
     *  ExpectedIncomeController::resolveSelectedTeam() already uses for an
     *  invalid/stale team slug. */
    private function resolveSelectedMonth(Request $request): string
    {
        $month = $request->input('month');

        if ($month !== null) {
            session(['projections.month' => $month]);
        } else {
            $month = session('projections.month', now()->format('Y-m'));
        }

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : now()->format('Y-m');
    }

    /** The same session-remembered month index() itself reads/writes, for
     *  the 3 AJAX endpoints below (updateRates/storeCustomRow/
     *  destroyCustomRow) — none of them receive a fresh ?month= of their
     *  own (they're field-level saves triggered from whichever month's
     *  page is already open, not a full page navigation), so this is the
     *  only month they can correctly scope their own 'computed' response
     *  to. Never writes to the session (only index()'s own
     *  resolveSelectedMonth() does that) — purely a read. */
    private function currentSessionMonth(): string
    {
        return session('projections.month', now()->format('Y-m'));
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
            'roas'                       => ['sometimes', 'numeric', 'min:0'],
            'standard_cost_per_message'  => ['sometimes', 'numeric', 'min:0'],
            'actual_cost_per_lead'       => ['sometimes', 'numeric', 'min:0'],
            'net_income_target'   => ['sometimes', 'numeric', 'min:0'],
            'average_order_value' => ['sometimes', 'numeric', 'min:0'],
            // Nullable so clearing the field back to blank restores the
            // target-derived # of Orders instead of pinning it at 0 — see
            // the add_orders_override migration's own doc comment.
            'orders_override'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Same nullable-override convention, for Number of Leads/
            // Conversion Rate — see the add_leads_and_conversion_overrides
            // migration's own doc comment. conversion_rate_override is a
            // fraction (0.30 = 30%), not a percentage, same convention as
            // every shared rate elsewhere on this page.
            'leads_override'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'conversion_rate_override'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Same nullable-override convention, for Target Upselling Rate
            // — see the add_upselling_rate_override migration's own doc
            // comment.
            'upselling_rate_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tsa_count'            => ['sometimes', 'integer', 'min:1'],
            'label'                => ['sometimes', 'string', 'max:255'],
            // Lock toggle (explicit request, 2026-10-02: "add lock icon ...
            // when it is lock it can't edit") — freezes every one of this
            // card's own editable fields as read-only, same as a derived
            // card already renders, without touching stored values. Only
            // meaningful on Opening/Closing Shift (the only 2 cards with
            // any real editable input in the first place — see
            // _column.blade.php's own $editable), but not restricted here
            // since locking an already-read-only derived card is harmless.
            'is_locked'            => ['sometimes', 'boolean'],
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

        $response = [
            'success'  => true,
            'computed' => $all[$column->key] ?? null,
            'all'      => array_values($all),
        ];

        // The lock toggle swaps every field on this card between a real
        // <input> and a read-only <span> — a structural change applyComputed()
        // can't express (it only ever updates an EXISTING element's own
        // text/value, never swaps an element's tag). Rendering the card's
        // own partial server-side and returning it here lets the frontend
        // cross-fade the whole card in place (explicit request, 2026-10-02:
        // "make a smooth transition of lock ... like animation") instead of
        // a full page reload — only rendered when this request actually
        // touched is_locked, since every other per-field save still only
        // needs the cheap JSON path above.
        if (array_key_exists('is_locked', $data)) {
            // $rates isn't part of $entry — _column.blade.php reads it as
            // its own top-level view variable (implicitly inherited from
            // the parent data.projections view's own @include() calls on a
            // full page render), so it has to be passed explicitly here too.
            $response['cardHtml'] = view('data.projections._column', ['entry' => $response['computed'], 'rates' => $rates])->render();
        }

        return response()->json($response);
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
        // Scoped to the session-remembered month (explicit decision,
        // 2026-10-02: rates stay GLOBAL across every month, but a live
        // rate-edit's own recomputed 'computed' payload still has to match
        // whichever month's own column INPUTS the page currently has
        // open, same session key index() itself reads/writes) — this is
        // an AJAX call, not a full page load, so there's no fresh ?month=
        // on this request to resolve from directly.
        $columns = ProjectionColumn::where('month', $this->currentSessionMonth())->orderBy('sort_order')->get();

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
        // Scoped to the session-remembered month — see updateRates()'s own
        // doc comment; the frontend reloads the page on success anyway
        // (window.location.reload()), so this 'computed' payload is
        // currently unused, but kept correct regardless.
        $columns = ProjectionColumn::where('month', $this->currentSessionMonth())->orderBy('sort_order')->get();

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
        // Scoped to the session-remembered month — see updateRates()'s own
        // doc comment; same "frontend reloads on success anyway" caveat.
        $columns = ProjectionColumn::where('month', $this->currentSessionMonth())->orderBy('sort_order')->get();

        return response()->json([
            'success'  => true,
            'rates'    => $rates,
            'computed' => array_values(ProjectionCalculator::forAllColumns($columns, $rates)),
        ]);
    }
}
