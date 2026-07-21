<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Leads Report's window mode ('last24h' rolling vs 'dates' explicit range)
 * used to be re-derived from the request on every visit, defaulting straight
 * back to 'last24h' the moment a request omitted ?range= — e.g. clicking the
 * sidebar link to leave and come back. That silently discarded whatever date
 * range a user had just picked, even though team/dates were already sitting in
 * session. Mode now persists the same way team does, with an explicit "Last
 * 24h" button (range=last24h) as the only way back to the rolling window.
 */
class LeadsReportModePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_explicit_dates_range_survives_a_bare_revisit_with_no_range_param(): void
    {
        $from = now()->subDays(3)->toDateString();
        $to   = now()->subDays(2)->toDateString();

        $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $from, 'date_to' => $to,
        ]))->assertOk();

        // Bare revisit — exactly what clicking the sidebar link produces.
        $response = $this->get(route('leads-report'));

        $response->assertOk();
        $response->assertViewHas('mode', 'dates');
        $response->assertViewHas('dateFrom', $from);
        $response->assertViewHas('dateTo', $to);
    }

    public function test_explicit_range_last24h_resets_a_sticky_dates_session_back_to_rolling(): void
    {
        $from = now()->subDays(5)->toDateString();

        $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $from, 'date_to' => $from,
        ]))->assertOk();

        $response = $this->get(route('leads-report', ['range' => 'last24h']));

        $response->assertOk();
        $response->assertViewHas('mode', 'last24h');

        // And that reset itself sticks on the next bare revisit too.
        $again = $this->get(route('leads-report'));
        $again->assertViewHas('mode', 'last24h');
    }
}
