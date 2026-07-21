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
     * Regression test for the Excess/Catered definition: Excess = an order NO TSA
     * ever claimed (tsa_name === null) AND either (a) genuinely no tag at all —
     * the current definition since 2026-07-21, matching Pancake's own order-tag
     * filter's "No tag" option, once the team stopped applying any specific sweep
     * tag — or (b) the legacy disposition === "UNCATERED LEADS", kept only so
     * pre-2026-07-21 rows (which DO carry other tags alongside it) still count the
     * same way they always did. Everything else is Catered — including a null
     * disposition on an order that still has OTHER tags (worked orders routinely
     * have one because their tags are a TSA name + product + "Picked up"/"On
     * delivery"/"UPSELL TSD", none of which are dispositions — and, since
     * 2026-07-21, an unclaimed order carrying non-disposition tags like a bare
     * product tag is also Catered, not Excess, matching Pancake's "No tag" filter
     * only matching TRUE tag-emptiness) and a stale "UNCATERED LEADS" tag on an
     * order a TSA already worked. Confirmed directly against real Pancake POS data
     * (2026-07-05): the old "null OR UNCATERED" rule inflated CLEARSIGHT's Excess
     * to 18 and PTERYGIUM's to 28 when the true figure for both was 0.
     */
    public function test_excess_is_unclaimed_uncatered_leads_only_everything_worked_is_catered(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $today = now()->toDateString();

        // [pancake_order_id => [tsa_name, disposition, raw_tags]]
        $orders = [
            // Legacy: swept UNCATERED LEADS (pre-2026-07-21 style, alongside other
            // tags), no TSA ever claimed it → Excess
            'excess-unassigned'      => [null,            'UNCATERED LEADS', ['CLEARSIGHT', 'UNCATERED LEADS']],
            // Current definition: genuinely no tag at all, no TSA → Excess
            'excess-no-tags'         => [null,             null,             []],
            // Unclaimed but NOT tag-empty (a bare product tag, no disposition, no TSA)
            // → Catered, not Excess — distinguishes "no tag at all" from "null disposition"
            'catered-untagged-disp'  => [null,             null,             ['CLEARSIGHT']],
            // Worked order with no recognized disposition keyword (e.g. only "Picked up") → Catered
            'catered-null-disp'      => [$shift->tsa_key,  null,             ['JULIE', 'CLEARSIGHT']],
            // Order a TSA worked, then a midnight sweep re-tagged UNCATERED LEADS → Catered
            'catered-stale-sweep'    => [$shift->tsa_key,  'UNCATERED LEADS', ['JULIE', 'UNCATERED LEADS']],
            // "Call in Progress" has no dedicated column but is still a real disposition → Catered
            'catered-cip'            => [$shift->tsa_key,  'Call in Progress (Sinuxyl)', ['JULIE', 'Call in Progress (Sinuxyl)']],
            // Explicit answered disposition → Catered
            'catered-confirmed'      => [$shift->tsa_key,  'CONFIRMED VIA CALL', ['JULIE', 'CONFIRMED VIA CALL']],
        ];

        foreach ($orders as $id => [$tsaName, $disposition, $rawTags]) {
            Order::create([
                'pancake_order_id'   => $id,
                'team'               => 'SH Naturals',
                'tsa_name'           => $tsaName,
                'disposition'        => $disposition,
                'raw_tags'           => $rawTags,
                'is_upsell'          => false,
                'status_code'        => $disposition === null || $disposition === 'UNCATERED LEADS' ? 0 : 1,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ]);
        }

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => $today]));

        $response->assertOk();
        // 7 total: 2 excess (legacy UNCATERED tag + genuinely tag-empty), 5 catered
        $response->assertViewHas('totals', function ($totals) {
            return $totals['total'] === 7 && $totals['excess'] === 2 && $totals['catered'] === 5;
        });
    }
}
