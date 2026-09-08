<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\Teams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Root-caused 2026-09-08 (real production report: Team Comparison showed
 * Team Closing as "Leading" — 16 calls, 6 upsells, ₱4,700 revenue, 37.5%
 * upsell rate — over Team Opening — 152 calls, 55 upsells, ₱47,600 revenue,
 * 36.2% rate — purely because Closing's rate was a fraction of a point
 * higher, despite Opening's real sales being an order of magnitude larger
 * on every other number on the same card). Cause: the "Leading" badge
 * compared upsell_rate, a percentage that a tiny sample can win by chance,
 * not actual sales performance. Explicit correction: "the leading should be
 * the team that is leading in sales" — now compares 'revenue' instead,
 * matching what the "Revenue" figure already shown on each card means.
 */
class DashboardTeamComparisonLeadingBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_leading_badge_reflects_revenue_not_upsell_rate(): void
    {
        // SH Naturals — small sample, slightly higher RATE (1/2 = 50%),
        // tiny revenue.
        Order::create([
            'pancake_order_id' => 'sh-called', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => '2026-09-08 10:00:00', 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'sh-upsell', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => '2026-09-08 10:05:00', 'synced_at' => now(),
        ]);

        // Eyecare Team — much larger real volume/revenue, slightly LOWER
        // rate (2/5 = 40%), but real sales dwarf SH Naturals'.
        foreach (range(1, 3) as $i) {
            Order::create([
                'pancake_order_id' => "eye-called-{$i}", 'team' => 'Eyecare Team', 'tsa_name' => 'Julie',
                'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
                'pancake_created_at' => "2026-09-08 11:0{$i}:00", 'synced_at' => now(),
            ]);
        }
        foreach (range(1, 2) as $i) {
            Order::create([
                'pancake_order_id' => "eye-upsell-{$i}", 'team' => 'Eyecare Team', 'tsa_name' => 'Julie',
                'is_upsell' => true, 'amount' => 10000.0, 'status_code' => 2,
                'pancake_created_at' => "2026-09-08 11:1{$i}:00", 'synced_at' => now(),
            ]);
        }

        $response = $this->get(route('dashboard', [
            'team' => 'all', 'date_from' => '2026-09-08', 'date_to' => '2026-09-08',
        ]));

        $response->assertOk();
        $teamComparison = $response->viewData('teamComparison');

        // Resolved DISPLAY names (e.g. "TEAM CLOSING"/"TEAM OPENING" on
        // this shop, per its own Settings-configured team labels), not the
        // fixed order_team strings ('SH Naturals'/'Eyecare Team') the
        // orders above were seeded with — $teamComparison's own 'name'
        // field is already resolved through Teams::nameForOrderTeamRange(),
        // same lookup used here to find each team's row without hardcoding
        // whatever this shop happens to currently call them.
        $date = Carbon::parse('2026-09-08');
        $shNaturalsName = Teams::nameForOrderTeamRange('SH Naturals', $date, $date);
        $eyecareName    = Teams::nameForOrderTeamRange('Eyecare Team', $date, $date);

        $shNaturals = $teamComparison->firstWhere('name', $shNaturalsName);
        $eyecare    = $teamComparison->firstWhere('name', $eyecareName);

        // Confirms the test's own premise: SH Naturals really does have the
        // higher rate here, so a rate-based badge would (wrongly) pick it.
        $this->assertGreaterThan($eyecare['upsell_rate'], $shNaturals['upsell_rate']);
        // But Eyecare has the real revenue lead.
        $this->assertGreaterThan($shNaturals['revenue'], $eyecare['revenue']);

        // The actual fix, exercised through the real rendered HTML — the
        // team name text appears many times across the page (TSA
        // Leaderboard's own per-TSA team labels, etc.), so a real DOM
        // query is needed to isolate specifically the Team Comparison
        // SECTION's own two cards rather than string-searching the whole
        // page. dashboard.blade.php always renders a 9x9 rounded-full
        // avatar circle immediately before the team name inside each of
        // these cards (see the @foreach: <span class="w-9 h-9
        // rounded-full ...">{{ initial }}</span><p>{{ $team['name'] }}</p>)
        // — a shape unique to this section (the TSA Leaderboard's own
        // avatars are a different size/class), so XPath can reliably
        // find just these two cards and check each one's own "Leading"
        // presence independently.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_use_internal_errors(false);
        $xpath = new \DOMXPath($dom);

        $teamNameNodes = $xpath->query("//span[contains(@class, 'w-9 h-9') and contains(@class, 'rounded-full')]/following-sibling::p[1]");
        $cardsByName = [];
        foreach ($teamNameNodes as $node) {
            // The whole card is this <p>'s ancestor 3 levels up (p -> name/
            // avatar flex row -> header flex row -> card div) — walking up
            // to the nearest ancestor that also contains "Leading" or not
            // is simpler and just as reliable: grab the OUTER card div by
            // walking up parentNode until hitting the shadow-sm card root.
            $cardDiv = $node;
            while ($cardDiv->parentNode && (!$cardDiv->hasAttribute('class') || !str_contains($cardDiv->getAttribute('class'), 'shadow-sm'))) {
                $cardDiv = $cardDiv->parentNode;
            }
            $cardsByName[trim($node->textContent)] = $dom->saveHTML($cardDiv);
        }

        $this->assertArrayHasKey($eyecareName, $cardsByName);
        $this->assertArrayHasKey($shNaturalsName, $cardsByName);
        $this->assertStringContainsString('Leading', $cardsByName[$eyecareName], 'Leading badge must be on Eyecare\'s card (real revenue leader)');
        $this->assertStringNotContainsString('Leading', $cardsByName[$shNaturalsName], 'Leading badge must NOT be on SH Naturals\' card (higher rate, but far lower revenue)');
    }
}
