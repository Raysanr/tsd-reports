<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: add the same ALL/SH Naturals/Eyecare filter Leads Report
 * and TSA Performance already have to the Dashboard too. Every KPI/widget
 * already reads $orderTeams (see DashboardController::index()'s own comment),
 * so scoping that one array to the selected team is what makes the whole page
 * follow the filter.
 */
class DashboardTeamFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $team, string $tsaName, bool $isUpsell = true): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => $team,
            'tsa_name'            => $tsaName,
            'product'             => 'Test Product',
            'disposition'         => $isUpsell ? null : 'CONFIRMED VIA CALL',
            'raw_tags'            => $isUpsell ? [$tsaName, 'UPSELL TSD (TEST)'] : [$tsaName, 'CONFIRMED VIA CALL'],
            'is_upsell'           => $isUpsell,
            'amount'              => 500.0,
            'status_code'         => 1,
            'pancake_created_at'  => '2026-07-22 10:00:00',
            'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at'           => now(),
        ]);
    }

    public function test_defaults_to_all_teams_when_no_team_param_is_given(): void
    {
        $this->order('team-1', 'SH Naturals', 'Gemma');
        $this->order('team-2', 'Eyecare Team', 'Marisol');

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('selectedTeam', 'all');
        $response->assertViewHas('stats', fn ($stats) => $stats['total_orders'] === 2);
    }

    public function test_selecting_sh_naturals_excludes_eyecare_orders(): void
    {
        $this->order('team-3', 'SH Naturals', 'Gemma');
        $this->order('team-4', 'Eyecare Team', 'Marisol');

        $response = $this->get(route('dashboard', [
            'team' => 'sh-naturals', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedTeam', 'sh-naturals');
        $response->assertViewHas('stats', fn ($stats) => $stats['total_orders'] === 1);
        $response->assertViewHas('recentOrders', fn ($orders) => $orders->pluck('pancake_order_id')->all() === ['team-3']);
    }

    public function test_selecting_eyecare_excludes_sh_naturals_orders(): void
    {
        $this->order('team-5', 'SH Naturals', 'Gemma');
        $this->order('team-6', 'Eyecare Team', 'Marisol');

        $response = $this->get(route('dashboard', [
            'team' => 'eyecare', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_orders'] === 1);
        $response->assertViewHas('recentOrders', fn ($orders) => $orders->pluck('pancake_order_id')->all() === ['team-6']);
    }

    public function test_an_unknown_team_param_falls_back_to_all(): void
    {
        $this->order('team-7', 'SH Naturals', 'Gemma');
        $this->order('team-8', 'Eyecare Team', 'Marisol');

        $response = $this->get(route('dashboard', [
            'team' => 'not-a-real-team', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedTeam', 'all');
        $response->assertViewHas('stats', fn ($stats) => $stats['total_orders'] === 2);
    }

    public function test_team_comparison_panel_is_empty_once_a_specific_team_is_selected(): void
    {
        $this->order('team-9', 'SH Naturals', 'Gemma');
        $this->order('team-10', 'Eyecare Team', 'Marisol');

        $all = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));
        $all->assertViewHas('teamComparison', fn ($rows) => $rows->count() === 2);

        $filtered = $this->get(route('dashboard', [
            'team' => 'sh-naturals', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));
        $filtered->assertViewHas('teamComparison', fn ($rows) => $rows->isEmpty());
    }

    public function test_restocking_by_team_only_shows_the_selected_team(): void
    {
        Order::create([
            'pancake_order_id' => 'restock-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 1000.0,
            'restocking_upsell_amount' => 300.0, 'status_code' => 11,
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'restock-2', 'team' => 'Eyecare Team', 'tsa_name' => 'Marisol',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 1000.0,
            'restocking_upsell_amount' => 400.0, 'status_code' => 11,
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('dashboard', [
            'team' => 'sh-naturals', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('restockingByTeam', function ($rows) {
            return $rows->count() === 1 && $rows->first()['name'] === 'SH Naturals';
        });
    }

    /**
     * Confirmed while adding this filter: the Hourly Activity shift-start cutoff
     * (DashboardController::index()) used to consider EVERY TSA's shift start
     * regardless of the team filter — filtering to Eyecare alone could still pick
     * up SH Naturals' earliest shift (6 AM here) as the cutoff, even though no
     * Eyecare TSA starts that early. It must use only the selected team's own TSAs.
     */
    public function test_shift_cutoff_uses_only_the_selected_teams_own_tsa_shift_start(): void
    {
        TsaShift::where('tsa_key', 'Gemma')->update(['shift_start' => '06:00']); // SH Naturals
        TsaShift::where('tsa_key', 'Marisol')->update(['shift_start' => '09:00']); // Eyecare

        Order::create([
            'pancake_order_id' => 'cutoff-eyecare-1', 'team' => 'Eyecare Team', 'tsa_name' => 'Marisol',
            'product' => 'Test Product', 'disposition' => 'CONFIRMED VIA CALL',
            'raw_tags' => ['MARISOL', 'CONFIRMED VIA CALL'], 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => '2026-07-22 07:00:00', 'pancake_inserted_at' => '2026-07-22 07:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('dashboard', [
            'team' => 'eyecare', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        // 7am is before Eyecare's own earliest shift (Marisol, 9am) — the
        // 6am SH Naturals shift start must NOT apply here. Backlog absorbed at 9am.
        $response->assertViewHas('hourlyActivity', fn ($activity) => $activity[7] === 0 && $activity[9] === 1);
    }
}
