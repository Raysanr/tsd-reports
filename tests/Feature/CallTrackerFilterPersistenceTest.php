<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit request (2026-08-24): "when i filter something in the one tab
 * and click other tab i want to make it will be that filter when i go
 * back" — every Call Tracker sidebar link is a plain GET with no params
 * (see layouts/calls.blade.php), so navigating away and back used to reset
 * every page's date/team filter to its hard default every time. Covers
 * PersistsCallTrackerFilters (session-based, per-page) across the pages
 * that use it — a genuine two-request round trip per test, not just
 * inspecting the controller's return value once, so this actually proves
 * the "go to a different tab and come back" scenario the request describes.
 */
class CallTrackerFilterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('calltracker:reconcile-roster');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_dashboard_remembers_team_and_date_range_on_a_bare_revisit(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.dashboard', ['team' => 'sh-naturals', 'date_from' => '2026-08-01', 'date_to' => '2026-08-05']))
            ->assertOk();

        // Simulates leaving to another tab and coming back — a bare GET
        // with no query params, exactly what every sidebar link sends.
        $response = $admin->get(route('calls.dashboard'));

        $response->assertOk();
        $this->assertSame('sh-naturals', $response->viewData('selectedTeam'));
        $this->assertSame('2026-08-01', $response->viewData('dateFrom')->toDateString());
        $this->assertSame('2026-08-05', $response->viewData('dateTo')->toDateString());
    }

    public function test_dashboard_filter_is_still_the_hard_default_on_a_first_ever_visit(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $response->assertOk();
        $this->assertSame('all', $response->viewData('selectedTeam'));
        $this->assertTrue($response->viewData('isToday'));
    }

    public function test_monitor_tsa_remembers_team_on_a_bare_revisit(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.monitor', ['team' => 'eyecare']))->assertOk();

        $response = $admin->get(route('calls.monitor'));

        $response->assertOk();
        $this->assertSame('eyecare', $response->viewData('selectedTeam'));
    }

    public function test_call_log_remembers_team_and_date_range_on_a_bare_revisit(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.call-log', ['team' => 'sh-naturals', 'date_from' => '2026-08-10', 'date_to' => '2026-08-12']))
            ->assertOk();

        $response = $admin->get(route('calls.call-log'));

        $response->assertOk();
        $this->assertSame('sh-naturals', $response->viewData('selectedTeam'));
        $this->assertSame('2026-08-10', $response->viewData('dateFrom'));
        $this->assertSame('2026-08-12', $response->viewData('dateTo'));
    }

    /** Two different Call Tracker pages must NOT share one filter — picking
     *  a team on Dashboard has no effect on what Monitor TSA shows, same as
     *  each page's own default already worked before this change. */
    public function test_each_page_remembers_its_own_filter_independently(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.dashboard', ['team' => 'sh-naturals']))->assertOk();
        $admin->get(route('calls.monitor', ['team' => 'eyecare']))->assertOk();

        $dashboard = $admin->get(route('calls.dashboard'));
        $monitor   = $admin->get(route('calls.monitor'));

        $this->assertSame('sh-naturals', $dashboard->viewData('selectedTeam'));
        $this->assertSame('eyecare', $monitor->viewData('selectedTeam'));
    }

    /** TSA Management's "All teams" pill sends an EXPLICIT team= (empty),
     *  not an omitted param — has() (not filled()) is what makes this
     *  actually clear the remembered filter instead of leaving the old
     *  team in place (the bug this test guards against: an earlier draft
     *  used filled(), under which this exact scenario would have kept
     *  showing "SH Naturals" instead of "All teams"). */
    public function test_tsa_management_all_teams_pill_actually_clears_the_remembered_filter(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.tsa-management', ['team' => 'SH Naturals']))->assertOk();
        $stillFiltered = $admin->get(route('calls.tsa-management'));
        $this->assertSame('SH Naturals', $stillFiltered->viewData('selectedTeam'));

        $cleared = $admin->get(route('calls.tsa-management', ['team' => '']));
        $this->assertSame('', $cleared->viewData('selectedTeam'));

        // And it STAYS cleared on the next bare revisit too.
        $revisit = $admin->get(route('calls.tsa-management'));
        $this->assertSame('', $revisit->viewData('selectedTeam'));
    }

    public function test_tsa_logs_remembers_tsa_and_date_range_on_a_bare_revisit(): void
    {
        $admin = $this->actingAs($this->admin());
        $gemma = \App\Models\TsaShift::where('tsa_key', 'Gemma')->first();

        $admin->get(route('calls.tsa-logs', ['tsa' => $gemma->id, 'date_from' => '2026-08-01', 'date_to' => '2026-08-02']))
            ->assertOk();

        $response = $admin->get(route('calls.tsa-logs'));

        $response->assertOk();
        $this->assertSame($gemma->id, $response->viewData('selectedTsa'));
        $this->assertSame('2026-08-01', $response->viewData('dateFrom'));
        $this->assertSame('2026-08-02', $response->viewData('dateTo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Explicit request, 2026-08-28: "why even today it is exceed 329/75
     * even the tsa is not yet clicked in login today" — traced to a date
     * range picked on a previous day never expiring, so Leads Setup kept
     * comparing a stale multi-day sum against each TSA's DAILY cap. Unlike
     * team (meant to persist indefinitely, see the pill test above), a
     * remembered date range must fall back to today the moment the
     * calendar day actually changes.
     */
    public function test_leads_setup_date_range_auto_resets_to_today_on_a_new_calendar_day(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.round-robin-setup', ['date_from' => '2026-08-01', 'date_to' => '2026-08-27']))
            ->assertOk();

        // Same-day bare revisit — the picked range is still honored.
        $sameDay = $admin->get(route('calls.round-robin-setup'));
        $this->assertSame('2026-08-01', $sameDay->viewData('dateFrom')->toDateString());
        $this->assertSame('2026-08-27', $sameDay->viewData('dateTo')->toDateString());

        // Roll the clock to the next calendar day — a bare revisit must now
        // fall back to today, not keep showing the stale range.
        Carbon::setTestNow(now('Asia/Manila')->addDay());
        $nextDay = $admin->get(route('calls.round-robin-setup'));

        $nextDay->assertOk();
        $this->assertTrue($nextDay->viewData('dateFrom')->isToday());
        $this->assertTrue($nextDay->viewData('dateTo')->isToday());
    }

    /** Same staleness rule, different page — Dashboard's own date range must
     *  reset the same way (this bug was latent everywhere the trait's date
     *  params are used, not just Leads Setup). */
    public function test_dashboard_date_range_auto_resets_to_default_on_a_new_calendar_day(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->get(route('calls.dashboard', ['date_from' => '2026-08-01', 'date_to' => '2026-08-05']))
            ->assertOk();

        Carbon::setTestNow(now('Asia/Manila')->addDay());
        $nextDay = $admin->get(route('calls.dashboard'));

        $nextDay->assertOk();
        $this->assertTrue($nextDay->viewData('isToday'));
    }

    /** A date filter saved in session by the OLD code (before the staleness
     *  stamp existed) has no remembered_on to compare — must still be
     *  treated as stale on the very next request, not trusted forever just
     *  because there's nothing to compare against. */
    public function test_a_date_filter_remembered_before_this_fix_existed_still_self_heals(): void
    {
        $admin = $this->actingAs($this->admin());
        $this->withSession(['calltracker_filters.leads-setup.date_from' => '2026-08-01', 'calltracker_filters.leads-setup.date_to' => '2026-08-27']);

        $response = $admin->get(route('calls.round-robin-setup'));

        $response->assertOk();
        $this->assertTrue($response->viewData('dateFrom')->isToday());
        $this->assertTrue($response->viewData('dateTo')->isToday());
    }
}
