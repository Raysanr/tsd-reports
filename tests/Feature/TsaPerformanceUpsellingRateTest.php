<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceUpsellingRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_upselling_rate_is_upsell_over_upsell_plus_confirmed_via_call(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // 3 upsell orders (disposition deliberately NOT "confirmed via call", so it
        // doesn't also get counted as a confirmed_via_call order below).
        for ($i = 0; $i < 3; $i++) {
            Order::create([
                'pancake_order_id'   => "upsell-$i",
                'team'               => 'SH Naturals',
                'tsa_name'           => $shift->tsa_key,
                'disposition'        => 'UPSELL W CONFIRMATION',
                'is_upsell'          => true,
                'status_code'        => 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        // 2 confirmed-via-call-only orders (no upsell).
        for ($i = 0; $i < 2; $i++) {
            Order::create([
                'pancake_order_id'   => "confirmed-$i",
                'team'               => 'SH Naturals',
                'tsa_name'           => $shift->tsa_key,
                'disposition'        => 'CONFIRMED VIA CALL',
                'is_upsell'          => false,
                'status_code'        => 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        // Upselling Rate = 3 / (3 + 2) = 60.0%, NOT the old (broken) 3/5-of-total formula
        // which would coincidentally also read 60% here — so also assert against a
        // total that DIFFERS from (upsell + confirmed_via_call) to prove which formula
        // is actually running: add an unrelated, uncatered order that inflates total
        // but must NOT affect the rate under the correct formula.
        Order::create([
            'pancake_order_id'   => 'uncatered-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => null,
            'disposition'        => 'UNCATERED LEADS',
            'is_upsell'          => false,
            'status_code'        => 0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        $response->assertViewHas('totalUpsellingRate', 60.0);
    }
}
