<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: the card was hardcoded to say "Today's TSA Leaderboard"
 * regardless of which date_from/date_to was actually selected — confirmed
 * live that its DATA already reflects the picked range correctly, only the
 * label lied. Only genuinely says "Today's" when the selected range really
 * is today; otherwise shows the actual date/range it's displaying.
 */
class DashboardTsaLeaderboardLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_leaderboard_says_todays_when_the_selected_range_is_today(): void
    {
        $today = now()->toDateString();

        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        // No `false` here — Blade escapes the apostrophe to &#039; in the
        // real output, so the search string needs the same escaping applied
        // for the comparison to match, not raw text.
        $response->assertSee("Today's TSA Leaderboard");
    }

    public function test_the_leaderboard_shows_the_real_date_when_a_past_day_is_selected(): void
    {
        $pastDay = now()->subDays(2)->toDateString();

        $response = $this->get(route('dashboard', ['date_from' => $pastDay, 'date_to' => $pastDay]));

        $response->assertOk();
        $response->assertDontSee("Today's TSA Leaderboard");
        $response->assertSee('TSA Leaderboard', false);
        $response->assertSee(now()->subDays(2)->format('M j, Y'), false);
    }

    public function test_the_leaderboard_shows_a_range_when_a_multi_day_span_is_selected(): void
    {
        $from = now()->subDays(5)->toDateString();
        $to   = now()->subDays(2)->toDateString();

        $response = $this->get(route('dashboard', ['date_from' => $from, 'date_to' => $to]));

        $response->assertOk();
        $response->assertDontSee("Today's TSA Leaderboard");
        $response->assertSee(now()->subDays(5)->format('M j') . ' – ' . now()->subDays(2)->format('M j, Y'), false);
    }
}
