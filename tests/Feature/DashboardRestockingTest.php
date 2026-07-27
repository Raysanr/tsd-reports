<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: "Total Restocking" (and its By Brand/By TSA breakdowns)
 * should reflect TSA upsell revenue awaiting stock, not whole-order value —
 * a plain primary order sitting in Restocking status must NOT count here,
 * only an is_upsell=true one. amount already holds just the isolated add-on
 * price for those rows (see SyncTodayOrders' extractUpsellAmount()), so no
 * separate amount recomputation is needed, just the is_upsell filter.
 */
class DashboardRestockingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, bool $isUpsell, float $amount, string $team = 'SH Naturals', ?string $tsaName = 'Gemma'): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => $team,
            'tsa_name'            => $tsaName,
            'is_upsell'           => $isUpsell,
            'amount'              => $amount,
            'status_code'         => 11, // Restocking
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ]);
    }

    public function test_total_restocking_excludes_a_plain_non_upsell_order(): void
    {
        $this->order('r-1', isUpsell: false, amount: 1000.00);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['restocking_count'] === 0 && (float) $stats['restocking_value'] === 0.0);
    }

    public function test_total_restocking_includes_only_the_upsell_order(): void
    {
        $this->order('r-2', isUpsell: false, amount: 1000.00);
        $this->order('r-3', isUpsell: true, amount: 250.00);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['restocking_count'] === 1 && (float) $stats['restocking_value'] === 250.0);
    }

    public function test_restocking_breakdown_by_tsa_and_team_also_excludes_non_upsell_orders(): void
    {
        $this->order('r-4', isUpsell: false, amount: 1000.00, tsaName: 'Gemma');
        $this->order('r-5', isUpsell: true, amount: 300.00, tsaName: 'Gemma');

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('restockingByTsa', function ($rows) {
            $gemma = $rows->firstWhere('tsa_name', 'Gemma');
            return $gemma && (int) $gemma->restocking_count === 1 && (float) $gemma->restocking_value === 300.0;
        });
        $response->assertViewHas('restockingByTeam', function ($teams) {
            $shNaturals = collect($teams)->firstWhere('name', 'SH Naturals');
            return $shNaturals && $shNaturals['restocking_count'] === 1 && (float) $shNaturals['restocking_value'] === 300.0;
        });
    }
}
