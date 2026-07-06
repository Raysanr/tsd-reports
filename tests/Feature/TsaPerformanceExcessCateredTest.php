<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceExcessCateredTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for the Excess/Catered redefinition: Excess = disposition is
     * null or exactly "UNCATERED LEADS"; everything else — including "Call in
     * Progress", which has no dedicated column — counts as Catered. Confirmed
     * directly against real Pancake POS data earlier in this project.
     */
    public function test_excess_is_null_or_uncatered_leads_only_everything_else_is_catered(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        $dispositions = [
            'null-1'       => null,
            'uncatered-1'  => 'UNCATERED LEADS',
            'cip-1'        => 'Call in Progress (Sinuxyl)',
            'confirmed-1'  => 'CONFIRMED VIA CALL',
        ];

        foreach ($dispositions as $id => $disposition) {
            Order::create([
                'pancake_order_id'   => $id,
                'team'               => 'SH Naturals',
                'tsa_name'           => $shift->tsa_key,
                'disposition'        => $disposition,
                'is_upsell'          => false,
                'status_code'        => $disposition === null || $disposition === 'UNCATERED LEADS' ? 0 : 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        // 4 total: 2 excess (null, UNCATERED LEADS), 2 catered (Call in Progress, Confirmed via Call)
        $response->assertViewHas('totals', function ($totals) {
            return $totals['total'] === 4 && $totals['excess'] === 2 && $totals['catered'] === 2;
        });
    }
}
