<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaSalesEntry;
use App\Models\TsaShift;
use App\Models\TsaTiktokEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: "add page summary sales report in data
 * management like this in the sheets," then "i want to make it like
 * auto based on the current team and tsa" — TSA rows are the app's own
 * real TsaShift records, grouped by team, no admin row management left.
 * Smoke-level only — formulas themselves are independently verified in
 * TsaSalesCalculatorTest against the real source sheet.
 */
class TsaSalesReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create([
            'tsa_shift_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 5500, 'net_income' => -1379.95, 'ads_spent' => 1426.33,
            'total_orders' => 6, 'catered_leads' => 32, 'pickup_rate' => 0.50, 'upselling_rate' => 0.50,
        ]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee(strtoupper($tsa->display_name));
        $response->assertSee('OVERALL TOTAL');
    }

    /** CSV-download + PNG-snapshot icons (explicit request, 2026-10-05)
     *  on the summary table AND every daily chunk table — same shared
     *  partials/table-actions.blade.php every other report page already
     *  uses. */
    public function test_the_page_shows_export_icons_on_every_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('data-export-csv="tsrSummaryTable"', $content);
        $this->assertMatchesRegularExpression('/data-export-csv="tsrScroller-[a-z0-9-]+"/', $content);
    }

    /** Reversed 2026-10-07 (explicit request: "make it the data
     *  management module is visible for the TSA's (NORMAL USERS)", full
     *  edit access confirmed) — a normal user can now both view and edit
     *  Summary Sales Report, same as Projections/DSPPR/Expected Income;
     *  only Cost Breakdown stays admin-only within this module. */
    public function test_a_normal_user_can_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.tsa-sales'));

        $response->assertOk();
    }

    /** Per-date lock (explicit request, 2026-10-07: "add lock icon like
     *  in the dsppr") — same mechanism as DSPPR's own per-date lock, see
     *  TsaSalesLockedDate's own doc comment. */
    public function test_locking_a_date_persists_and_is_reflected_on_reload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(route('data.tsa-sales.toggle-lock', ['date' => $date]), ['locked' => true]);
        $response->assertOk()->assertJson(['success' => true, 'date' => $date, 'locked' => true]);
        $this->assertDatabaseHas('tsa_sales_locked_dates', ['entry_date' => $date . ' 00:00:00']);

        $page = $this->actingAs($admin)->get(route('data.tsa-sales', ['date_from' => $date, 'date_to' => $date]));
        $page->assertOk();
        $page->assertSee('data-tsr-date-header data-date="' . $date . '" data-locked="1"', false);
    }

    public function test_unlocking_a_date_removes_its_locked_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        \App\Models\TsaSalesLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(route('data.tsa-sales.toggle-lock', ['date' => $date]), ['locked' => false]);
        $response->assertOk()->assertJson(['success' => true, 'date' => $date, 'locked' => false]);

        $this->assertDatabaseMissing('tsa_sales_locked_dates', ['entry_date' => $date . ' 00:00:00']);
    }

    /** Normal (TSA) users have full edit access to this page (2026-10-07
     *  reversal — see RoleAccessTest's own doc comment on the same
     *  Data Management-wide decision), so locking a date is allowed for
     *  them too, same as every other write action here. */
    public function test_a_normal_user_can_toggle_a_date_lock(): void
    {
        $user = User::factory()->create(['role' => 'normal']);

        $this->actingAs($user)->patchJson(route('data.tsa-sales.toggle-lock', ['date' => today()->toDateString()]), ['locked' => true])->assertOk();
    }

    public function test_a_locked_date_refuses_a_direct_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $tsa = TsaShift::first();
        \App\Models\TsaSalesLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 999]
        );
        $response->assertStatus(422);
        $this->assertDatabaseMissing('tsa_sales_entries', ['tsa_shift_id' => $tsa->id, 'gross_sales' => 999]);
    }

    public function test_a_locked_date_refuses_a_direct_tiktok_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $tsa = TsaShift::first();
        \App\Models\TsaSalesLockedDate::create(['entry_date' => $date]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-tiktok-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 999]
        );
        $response->assertStatus(422);
    }

    public function test_an_unlocked_date_is_unaffected_by_a_different_locked_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lockedDate = today()->toDateString();
        $otherDate = today()->addDay()->toDateString();
        $tsa = TsaShift::first();
        \App\Models\TsaSalesLockedDate::create(['entry_date' => $lockedDate]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $otherDate]),
            ['gross_sales' => 777]
        );
        $response->assertOk();
    }

    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => today()->toDateString()]),
            ['gross_sales' => 5500, 'net_income' => -1379.95]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (-1379.95 / 5500)) < 0.0001);

        $this->assertDatabaseHas('tsa_sales_entries', ['tsa_shift_id' => $tsa->id, 'gross_sales' => 5500]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, TsaSalesEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, TsaSalesEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }

    public function test_tsas_are_grouped_by_their_real_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee('SH Naturals');
        $response->assertSee('Eyecare');
    }

    /** Explicit request, 2026-09-28: "make the opening is in first and the
     *  closing is in last" — config('teams') itself lists 'sh-naturals'
     *  before 'eyecare' (confirmed live: those slugs are currently
     *  displayed as "Team Closing"/"Team Opening" via the editable team-
     *  name-history feature). Reordered ONLY on this page, scoped to
     *  TsaSalesReportController::index() — every other page reading
     *  Teams::config() keeps its own existing order, per explicit
     *  confirmation this shouldn't change app-wide. */
    public function test_eyecare_team_is_listed_before_sh_naturals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSeeInOrder(['Eyecare', 'SH Naturals']);
    }

    /** Same whereBetween()-on-a-datetime-column bug fixed 2026-09-26 in
     *  DsPprReportController/ExpectedIncomeController — see
     *  DsPprReportSmokeTest's own regression test for the full root cause. */
    public function test_the_last_day_of_a_selected_range_is_not_dropped_from_the_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();

        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today()->subDay(), 'gross_sales' => 1000]);
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales', [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('1,500.00');
    }

    /** Same off-by-one root-caused 2026-09-27 in DsPprReportController's
     *  own identical daysUntil()->addDay() call — see that test's own doc
     *  comment for the full root cause (daysUntil() is already inclusive
     *  of its own end date). */
    public function test_the_daily_table_never_shows_a_day_past_the_selected_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales', [
            'date_from' => '2026-09-21',
            'date_to' => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertSee('Mon, Sep 21');
        $response->assertSee('Wed, Sep 30');
        $response->assertDontSee('Thu, Oct 1');
    }

    /** Explicit request, 2026-09-28: "the net income it should be green if
     *  positive ... and if negative it should be red." */
    /** Explicit request, 2026-10-03: "lahat ng number kapag nilagay ay
     *  black tapos sa net income mag green lang yung color niya kapag
     *  nakahit siya ng 4.2K and above then stay mo sa negative kapag red
     *  tapos kapag 1 - 4,199 yung net income stay lang siya sa black" —
     *  3 tiers, not the old plain negative=red/positive=green: negative
     *  stays red, 0–4,199.99 is now BLACK (not green), and green only
     *  starts at 4,200 itself (confirmed inclusive: >= 4200). Scoped to
     *  the real <input data-field="net_income"> element (not a bare
     *  assertSee of a color class, which could pass just because that
     *  class string happens to appear ANYWHERE else on the page). */
    public function test_a_net_income_of_4200_or_above_is_rendered_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 10000, 'net_income' => 4200]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-green-600[^"]*"/',
            $response->getContent(),
            'expected exactly 4,200.00 to be green (inclusive threshold)'
        );
    }

    /** The real regression this feedback was about: a net income BELOW
     *  4,200 (but still positive) used to render green under the old
     *  plain rule — it must now stay black (text-ink), same as every
     *  other non-Net-Income number on this page. */
    public function test_a_net_income_below_4200_but_positive_stays_black(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 3800, 'net_income' => 500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-ink[^"]*"/',
            $content,
            'expected 500.00 (below the 4,200 threshold) to be plain black (text-ink), not green'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-green-600[^"]*"/',
            $content,
            '500.00 must NOT be green — only 4,200 and above should be'
        );
    }

    /** 4,199.99 — one cent below the threshold — must still be black, not
     *  green; confirms the boundary is exact, not off-by-a-dollar. */
    public function test_a_net_income_just_below_the_4200_threshold_stays_black(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 10000, 'net_income' => 4199.99]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-green-600[^"]*"/',
            $response->getContent(),
            '4,199.99 must NOT be green — the threshold is 4,200 exactly'
        );
    }

    public function test_a_negative_net_income_is_rendered_red(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 3800, 'net_income' => -500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-red-600[^"]*"/',
            $response->getContent()
        );
    }

    /** Explicit request, 2026-10-03: "i want to make it can input negative
     *  amount ... if negative red and if positive it is green like in the
     *  dsppr page" — the Net Income INPUT itself (not just the read-only
     *  summary spans) is colored by its own saved value. */
    public function test_the_net_income_input_itself_is_colored_by_its_saved_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 3800, 'net_income' => -500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="net_income"[^>]*class="[^"]*text-red-600[^"]*"/',
            $response->getContent()
        );
    }

    /** Gross Sales is always plain black, regardless of its value —
     *  explicit request, 2026-10-05: "the gross sales is make numbers all
     *  black," reversing the earlier 2026-10-03 decision (recorded in git
     *  history) that had briefly applied Net Income's own 3-tier
     *  red/black/green rule to Gross Sales too. Only Net Income keeps
     *  that rule now, same as DSPPR's own page. */
    public function test_the_gross_sales_input_is_always_plain_black_regardless_of_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => -200, 'net_income' => 500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-ink[^"]*"/',
            $content,
            'a negative Gross Sales (-200.00) must still render plain black, not red'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-red-600[^"]*"/',
            $content
        );
    }

    public function test_a_large_gross_sales_stays_black_not_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 4200, 'net_income' => 0]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-ink[^"]*"/',
            $content,
            'expected 4,200.00 Gross Sales to be plain black, not green — the 4,200 threshold only applies to Net Income'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-green-600[^"]*"/',
            $content
        );
    }

    /** gross_sales/net_income accept negative numeric values, not just
     *  positive — min:0 would silently reject a typed loss/refund. */
    public function test_negative_gross_sales_and_net_income_save_successfully(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => today()->toDateString()]),
            ['gross_sales' => -1500, 'net_income' => -300]
        );

        $response->assertOk();
        $this->assertDatabaseHas('tsa_sales_entries', [
            'tsa_shift_id' => $tsa->id, 'gross_sales' => -1500, 'net_income' => -300,
        ]);
    }

    /** Explicit request, 2026-10-03: "i want to make this automated that
     *  is the data is from TSD LEADS REPORT - TSA PERFORMANCE PAGE,"
     *  confirmed read-only/fully-automated, then "the total orders is
     *  confirmation w/ upsell data." Total Orders/Catered Leads/Pick-up
     *  Rate/Upselling Rate must now come from real Order data (the same
     *  ProductPerformance::tally() TSA Performance itself uses), not a
     *  manually-saved TsaSalesEntry value — this creates real orders with
     *  a known disposition shape and checks the rendered page reflects
     *  them, ignoring whatever (if anything) was saved on the entry. */
    public function test_total_orders_catered_leads_and_rates_are_computed_from_real_orders_not_manual_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        // A stale manually-saved value that the automation must now ignore
        // entirely — if the view were still reading TsaSalesEntry's own
        // columns for these 4 fields, this test would see 999/0.99 instead
        // of the real tally below.
        TsaSalesEntry::create([
            'tsa_shift_id' => $tsa->id, 'entry_date' => $date,
            'total_orders' => 999, 'catered_leads' => 999, 'pickup_rate' => 0.99, 'upselling_rate' => 0.99,
        ]);

        // 2 real upsell confirmations (Total Orders = upsell_confirmation).
        foreach (range(1, 2) as $i) {
            Order::create([
                'pancake_order_id' => "auto-upsell-{$i}", 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
                'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
                'pancake_created_at' => "{$date} 10:00:00", 'synced_at' => now(),
            ]);
        }
        // 1 answered (Confirmed via Call) non-upsell lead.
        Order::create([
            'pancake_order_id' => 'auto-answered-1', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'is_upsell' => false, 'amount' => 300.0, 'status_code' => 2,
            'disposition' => 'CONFIRMED VIA CALL',
            'pancake_created_at' => "{$date} 11:00:00", 'synced_at' => now(),
        ]);
        // 1 unanswered (Not Answering) non-upsell lead.
        Order::create([
            'pancake_order_id' => 'auto-unanswered-1', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'is_upsell' => false, 'amount' => 300.0, 'status_code' => 2,
            'disposition' => 'NOT ANSWERING',
            'pancake_created_at' => "{$date} 12:00:00", 'synced_at' => now(),
        ]);

        // Catered = answered + unanswered = (confirmed_via_call + upsell_confirmation) + not_answering = 3 + 1 = 4.
        // Pick-up Rate = answered / (answered + unanswered) = 3 / 4 = 75%.
        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));
        $response->assertOk();

        $this->assertMatchesRegularExpression(
            '/<td[^>]*data-out="total_orders"[^>]*>\s*2\s*<\/td>/',
            $response->getContent(),
            'Total Orders should be the real upsell_confirmation count (2), not the stale saved 999'
        );
        $this->assertMatchesRegularExpression(
            '/<td[^>]*data-out="catered_leads"[^>]*>\s*4\s*<\/td>/',
            $response->getContent(),
            'Catered Leads should be the real answered+unanswered count (4), not the stale saved 999'
        );
        $this->assertMatchesRegularExpression(
            '/<td[^>]*data-out="pickup_rate"[^>]*>\s*75\.00%\s*<\/td>/',
            $response->getContent(),
            'Pick-up Rate should be the real 75.00% (3 answered / 4 called), not the stale saved 99.00%'
        );
        // AOV automated too (explicit follow-up, 2026-10-03: "also
        // automate AOV like TSA Performance's") — upsell_sales ÷
        // upsell_confirmation = (500+500) ÷ 2 = 500.00, NOT Gross Sales
        // ÷ Total Orders (Gross Sales here is 0, unsaved, which would
        // give 0.00 under the old formula).
        $this->assertMatchesRegularExpression(
            '/<td[^>]*data-out="aov"[^>]*>\s*500\.00\s*<\/td>/',
            $response->getContent(),
            'AOV should be the real upsell_sales/upsell_confirmation average (500.00), not Gross Sales / Total Orders'
        );
    }

    /** These 4 fields no longer have an <input> at all — only AOV/NI%
     *  (already read-only) and these 4 should render as plain [data-out]
     *  cells on the daily entry table. */
    public function test_the_automated_fields_render_as_read_only_cells_not_inputs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        foreach (['total_orders', 'catered_leads', 'pickup_rate', 'upselling_rate'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]*data-field="' . $field . '"/',
                $response->getContent(),
                "{$field} should no longer render as an editable <input>"
            );
        }
    }

    /** updateEntry() must reject these 4 fields now (even if a caller
     *  still sends them) — they're no longer writable at all. */
    public function test_updating_the_automated_fields_is_silently_ignored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 1000, 'total_orders' => 777, 'catered_leads' => 777, 'pickup_rate' => 0.77, 'upselling_rate' => 0.77]
        );

        $response->assertOk();
        $this->assertDatabaseHas('tsa_sales_entries', ['tsa_shift_id' => $tsa->id, 'gross_sales' => 1000]);
        $this->assertDatabaseMissing('tsa_sales_entries', ['tsa_shift_id' => $tsa->id, 'total_orders' => 777]);
    }

    /** Real gap caught live via Playwright, 2026-10-03: a TSA who has
     *  real orders today but has NEVER had Gross Sales/Net Income typed
     *  in for that day has NO TsaSalesEntry row at all — the daily
     *  table's own $dailyByKey lookup used to fall straight through to
     *  $emptyRaw (all zeros) for her, silently skipping the automated
     *  fields entirely since there was no entry row to merge them onto.
     *  A TSA's auto-computed figures must show up even with zero manual
     *  entries ever saved for her. */
    public function test_a_tsa_with_no_saved_entry_at_all_still_shows_her_real_automated_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        // Deliberately NO TsaSalesEntry::create() call here — she has
        // never had a single figure typed in for today.
        foreach (range(1, 3) as $i) {
            Order::create([
                'pancake_order_id' => "auto-noentry-upsell-{$i}", 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
                'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
                'pancake_created_at' => "{$date} 10:00:00", 'synced_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));
        $response->assertOk();

        $this->assertMatchesRegularExpression(
            '/<td[^>]*data-out="total_orders"[^>]*>\s*3\s*<\/td>/',
            $response->getContent(),
            'A TSA with real orders but no ever-saved entry row should still show her real Total Orders (3), not fall through to 0'
        );
    }

    /** Real gap caught live via Playwright immediately after the daily-
     *  table fix above, 2026-10-03: the TOP "TSA's Running Sales
     *  Performance" MTD summary table sums over $entries (grouped from
     *  real TsaSalesEntry ROWS only) completely separately from the
     *  daily table's own $dailyByKey — fixing the daily table's fallback
     *  left the summary table still silently at 0 for a TSA with no
     *  ever-saved entry, even though her daily-table cell for the same
     *  day was now correct. Both tables must agree. */
    public function test_the_mtd_summary_table_also_shows_a_no_entry_tsas_real_automated_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        foreach (range(1, 3) as $i) {
            Order::create([
                'pancake_order_id' => "auto-summary-upsell-{$i}", 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
                'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
                'pancake_created_at' => "{$date} 10:00:00", 'synced_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));
        $response->assertOk();

        preg_match('/<tr class="tsr-summary-row[^"]*" data-tsa-id="' . $tsa->id . '">.*?<\/tr>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find the MTD summary row for this TSA');
        $this->assertMatchesRegularExpression(
            '/data-out="total_orders">\s*3\s*</',
            $matches[0],
            'The MTD summary table row should show her real Total Orders (3) too, not just the daily table'
        );
    }

    /** TikTok Upsell — explicit request, 2026-10-05: a manually-run
     *  section, separate roster (TsaShift.tiktok_upsell flag) and raw
     *  numbers (TsaTiktokEntry) from the real teams above. Only a TSA
     *  flagged tiktok_upsell=true shows up here; every field is manual
     *  (unlike the real teams' automated Total Orders/Catered Leads/
     *  Pick-up/Upselling Rate). */
    public function test_a_tsa_flagged_for_tiktok_upsell_appears_in_that_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        TsaTiktokEntry::create([
            'tsa_shift_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 6000, 'net_income' => 753.94,
            'total_orders' => 5, 'catered_leads' => 10, 'pickup_rate' => 0.50, 'upselling_rate' => 0.50,
        ]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee('TIKTOK UPSELL');
        $response->assertSee('6,000.00');
    }

    /** A TSA NOT flagged tiktok_upsell should never show up in that
     *  section, even though she's a normal roster member elsewhere on
     *  the page. */
    public function test_a_tsa_not_flagged_for_tiktok_upsell_does_not_appear_in_that_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => false]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee('No TSAs in TikTok Upsell yet');
    }

    /** Unlike updateEntry(), Total Orders/Catered Leads/Pick-up Rate/
     *  Upselling Rate ARE accepted and persisted here — every field in
     *  the TikTok Upsell section is manual entry (see
     *  create_tsa_tiktok_entries_table migration's own doc comment). */
    public function test_updating_a_tiktok_entry_upserts_every_manual_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-tiktok-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 6000, 'net_income' => 753.94, 'total_orders' => 5, 'catered_leads' => 10, 'pickup_rate' => 0.5, 'upselling_rate' => 0.5]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (753.94 / 6000)) < 0.0001);
        $this->assertDatabaseHas('tsa_tiktok_entries', [
            'tsa_shift_id' => $tsa->id, 'gross_sales' => 6000, 'total_orders' => 5, 'catered_leads' => 10,
        ]);
    }

    public function test_updating_an_existing_tiktok_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-tiktok-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-tiktok-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, TsaTiktokEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, TsaTiktokEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }

    /** TikTok Upsell's own totals must fold into the page's OVERALL TOTAL
     *  row — same as every real team already does. */
    public function test_tiktok_upsell_totals_are_included_in_the_overall_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        TsaTiktokEntry::create([
            'tsa_shift_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 6000, 'net_income' => 753.94,
        ]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));
        $response->assertOk();

        preg_match('/<tr class="bg-black text-white font-bold tsr-overall-total-row">.*?<\/tr>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'expected to find the OVERALL TOTAL row');
        $this->assertMatchesRegularExpression('/data-out="gross_sales">\s*6,000\.00\s*</', $matches[0]);
    }

    /**
     * Daily entry — merged into ONE table per 7-day chunk (explicit
     * request, 2026-10-06: "in the sales summary report, can you make it
     * in one table?" — confirmed against the real sheet's own screenshot:
     * TEAM OPENING SHIFT / TEAM CLOSING SHIFT / TIKTOK UPSELL each as
     * their own section with a TOTAL row, inside one table, same shape
     * as the "TSA's Running Sales Performance" summary table above it).
     * Previously rendered as 3 SEPARATE boxed tables. Confirms only ONE
     * .tsr-days-table exists per chunk, and both real-team group labels
     * plus TIKTOK UPSELL all appear inside it.
     */
    public function test_the_daily_entry_section_renders_one_combined_table_per_chunk(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();

        preg_match_all('/class="[^"]*\btsr-days-table\b[^"]*"/', $content, $tableMatches);
        $this->assertCount(1, $tableMatches[0], 'expected exactly ONE .tsr-days-table for the single 7-day chunk, not one per group');

        preg_match('/<table class="[^"]*tsr-days-table[^"]*">.*?<\/table>/s', $content, $tableBody);
        $this->assertNotEmpty($tableBody, 'expected to find the combined daily table');
        $this->assertStringContainsString('EYECARE TOTAL:', $tableBody[0]);
        $this->assertStringContainsString('SH NATURALS TOTAL:', $tableBody[0]);
        $this->assertStringContainsString('TIKTOK UPSELL TOTAL:', $tableBody[0]);
    }

    /** Each group's own TOTAL row carries its own save-endpoint/isTiktok
     *  flag now (moved off the shared <table> — a single table can no
     *  longer carry one save-endpoint for every row once team AND TikTok
     *  rows share it) — confirms the real team's row points at
     *  update-entry (no data-is-tiktok) and TikTok's own row points at
     *  update-tiktok-entry (with data-is-tiktok="1"). */
    public function test_each_groups_total_row_carries_its_own_save_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();

        // "TIKTOK UPSELL TOTAL:"/"EYECARE TOTAL:" each appear TWICE on the
        // page — once in the top summary table's own group-total row,
        // once in the merged daily-entry table below it — so this scopes
        // to the daily table specifically (the one actually bearing
        // data-update-url-template/data-is-tiktok) rather than
        // strpos()'s first (summary table) hit.
        $dailyTableStart = strpos($content, 'tsr-days-table');
        $this->assertNotFalse($dailyTableStart, 'expected to find the daily entry table');

        $tiktokTotalPos = strpos($content, 'TIKTOK UPSELL TOTAL:', $dailyTableStart);
        $this->assertNotFalse($tiktokTotalPos);
        $tiktokRowStart = strrpos(substr($content, 0, $tiktokTotalPos), '<tr class="bg-slate-800');
        $tiktokRowHtml = substr($content, $tiktokRowStart, $tiktokTotalPos - $tiktokRowStart);
        $this->assertStringContainsString('data-is-tiktok="1"', $tiktokRowHtml);
        $this->assertStringContainsString(route('data.tsa-sales.update-tiktok-entry', ['tsaShift' => '__TSA__', 'date' => '__DATE__']), $tiktokRowHtml);

        $sinceEyecarePos = strpos($content, 'EYECARE TOTAL:', $dailyTableStart);
        $this->assertNotFalse($sinceEyecarePos);
        $eyecareRowStart = strrpos(substr($content, 0, $sinceEyecarePos), '<tr class="bg-slate-800');
        $eyecareRowHtml = substr($content, $eyecareRowStart, $sinceEyecarePos - $eyecareRowStart);
        $this->assertStringNotContainsString('data-is-tiktok', $eyecareRowHtml);
        $this->assertStringContainsString(route('data.tsa-sales.update-entry', ['tsaShift' => '__TSA__', 'date' => '__DATE__']), $eyecareRowHtml);
    }

    /** Each real TSA row in the daily entry section is tagged with its
     *  own group's TOTAL row sitting in the SAME tbody — confirms the
     *  tbody-scoping fix (refreshDayTotal()/saveField() now resolve the
     *  save-endpoint and day-total row from input.closest('tbody')
     *  instead of the whole shared table) by checking a TikTok row's
     *  marker class exists and a real team row does NOT carry it. */
    public function test_tiktok_rows_are_marked_separately_from_real_team_rows_in_the_daily_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $otherTsa = TsaShift::skip(1)->first();

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<tr class="tsr-row[^"]*tsr-tiktok-row[^"]*" data-tsa-id="' . $tsa->id . '"/',
            $content,
            "expected {$tsa->display_name}'s TikTok row to carry the tsr-tiktok-row marker"
        );
        if ($otherTsa) {
            preg_match('/<tr class="tsr-row[^"]*" data-tsa-id="' . $otherTsa->id . '"/', $content, $match);
            $this->assertNotEmpty($match, "expected to find {$otherTsa->display_name}'s own row");
            $this->assertStringNotContainsString('tsr-tiktok-row', $match[0]);
        }
    }

    /** The merged daily-entry table has its OWN "OVERALL TOTAL" row too
     *  (explicit follow-up, 2026-10-06, right after the 3-way merge
     *  above: "and there's overall total") — pools every group's own day
     *  raw rows together, real teams AND TikTok alike, same role the
     *  page's own summary-table OVERALL TOTAL plays for the whole range. */
    public function test_the_daily_table_has_its_own_overall_total_row_pooling_every_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 4000]);
        TsaTiktokEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 1500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales', [
            'date_from' => $date, 'date_to' => $date,
        ]));

        $response->assertOk();
        $content = $response->getContent();

        preg_match('/<tr class="bg-black text-white font-bold tsr-day-overall-total-row">.*?<\/tr>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'expected to find the daily table\'s own OVERALL TOTAL row');
        $this->assertMatchesRegularExpression('/data-out="gross_sales"[^>]*data-date="' . preg_quote($date, '/') . '"[^>]*>\s*5,500\.00/', $matches[0], 'expected 4000 (real team) + 1500 (TikTok) = 5,500.00');
    }
}
