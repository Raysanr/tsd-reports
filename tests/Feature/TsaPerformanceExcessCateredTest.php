<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceExcessCateredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * Regression test for the Excess/Catered definition: Excess = a lead swept
     * "UNCATERED LEADS" that NO TSA ever claimed (disposition === "UNCATERED LEADS"
     * AND tsa_name === null). Everything else is Catered — including a null
     * disposition (which only means "no recognized disposition keyword"; worked
     * orders routinely have one because their tags are a TSA name + product +
     * "Picked up"/"On delivery"/"UPSELL TSD", none of which are dispositions) and a
     * stale "UNCATERED LEADS" tag on an order a TSA already worked. Confirmed
     * directly against real Pancake POS data (2026-07-05): the old "null OR
     * UNCATERED" rule inflated CLEARSIGHT's Excess to 18 and PTERYGIUM's to 28 when
     * the true figure for both was 0.
     */
    public function test_excess_is_unclaimed_uncatered_leads_only_everything_worked_is_catered(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // [pancake_order_id => [tsa_name, disposition]]
        $orders = [
            // Genuinely uncatered: swept UNCATERED LEADS, no TSA ever claimed it → Excess
            'excess-unassigned'   => [null,             'UNCATERED LEADS'],
            // Worked order with no recognized disposition keyword (e.g. only "Picked up") → Catered
            'catered-null-disp'   => [$shift->tsa_key,  null],
            // Order a TSA worked, then a midnight sweep re-tagged UNCATERED LEADS → Catered
            'catered-stale-sweep' => [$shift->tsa_key,  'UNCATERED LEADS'],
            // "Call in Progress" has no dedicated column but is still a real disposition → Catered
            'catered-cip'         => [$shift->tsa_key,  'Call in Progress (Sinuxyl)'],
            // Explicit answered disposition → Catered
            'catered-confirmed'   => [$shift->tsa_key,  'CONFIRMED VIA CALL'],
        ];

        foreach ($orders as $id => [$tsaName, $disposition]) {
            Order::create([
                'pancake_order_id'   => $id,
                'team'               => 'SH Naturals',
                'tsa_name'           => $tsaName,
                'disposition'        => $disposition,
                'is_upsell'          => false,
                'status_code'        => $disposition === null || $disposition === 'UNCATERED LEADS' ? 0 : 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        // 5 total: 1 excess (unclaimed UNCATERED LEADS), 4 catered (everything a TSA touched)
        $response->assertViewHas('totals', function ($totals) {
            return $totals['total'] === 5 && $totals['excess'] === 1 && $totals['catered'] === 4;
        });
    }
}
