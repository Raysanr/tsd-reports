<?php

namespace Tests\Feature;

use App\Models\TsaSalesEntry;
use App\Models\TsaShift;
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

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.tsa-sales'));

        $response->assertForbidden();
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

    /** Same coloring applied to Gross Sales too (explicit confirmation —
     *  unlike DSPPR, which only colors Net Income, BOTH money inputs get
     *  it here). */
    public function test_the_gross_sales_input_itself_is_colored_by_its_saved_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => -200, 'net_income' => 500]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-red-600[^"]*"/',
            $response->getContent()
        );
    }

    /** Explicit follow-up, 2026-10-03: "the gross sales too is black" —
     *  Gross Sales now shares Net Income's own 3-tier rule (negative=red,
     *  0–4,199.99=black, 4,200+=green), not the older plain
     *  negative=red/positive=green rule it was still stuck on right
     *  after Net Income alone was switched over. */
    public function test_a_gross_sales_below_4200_but_positive_stays_black(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 500, 'net_income' => 0]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-ink[^"]*"/',
            $content,
            'expected 500.00 Gross Sales (below the 4,200 threshold) to be plain black (text-ink), not green'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-green-600[^"]*"/',
            $content,
            '500.00 Gross Sales must NOT be green — only 4,200 and above should be'
        );
    }

    public function test_a_gross_sales_of_4200_or_above_is_rendered_green(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create(['tsa_shift_id' => $tsa->id, 'entry_date' => today(), 'gross_sales' => 4200, 'net_income' => 0]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-field="gross_sales"[^>]*class="[^"]*text-green-600[^"]*"/',
            $response->getContent(),
            'expected exactly 4,200.00 Gross Sales to be green (inclusive threshold)'
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
}
