<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: clicking a leads-count cell on TSA Performance should
 * show which specific orders (id + creation time) made up that number.
 * TsaPerformanceController::drilldown() re-derives the same categorization
 * rules buildRow() uses to compute the count, but returns the matching Order
 * models instead — tested here against real order rows so the returned list
 * genuinely matches what the main table would have counted.
 */
class TsaPerformanceDrilldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $tsa, string $time, ?string $disposition, bool $isUpsell = false): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => 'SH Naturals',
            'tsa_name'            => $tsa,
            'disposition'         => $disposition,
            'is_upsell'           => $isUpsell,
            'status_code'         => 1,
            'pancake_created_at'  => $time,
            'pancake_inserted_at' => $time,
            'synced_at'           => now(),
        ]);
    }

    public function test_drilldown_returns_the_orders_behind_a_single_tsas_column_for_one_hour(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        $this->order('dd-1', $shift->tsa_key, '2026-07-22 08:15:00', 'CONFIRMED VIA CALL');
        $this->order('dd-2', $shift->tsa_key, '2026-07-22 08:45:00', 'CONFIRMED VIA CALL');
        // Different hour — must NOT show up in the 8am drilldown.
        $this->order('dd-3', $shift->tsa_key, '2026-07-22 09:05:00', 'CONFIRMED VIA CALL');

        $response = $this->getJson(route('tsa-performance.drilldown', [
            'team' => 'sh-naturals', 'tsa' => $shift->tsa_key, 'hour' => 8,
            'column' => 'confirmed_via_call', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains('dd-1'));
        $this->assertTrue($ids->contains('dd-2'));
        $this->assertFalse($ids->contains('dd-3'));

        // Time is formatted, not raw — proves the response shape the popover expects.
        $response->assertJsonFragment(['id' => 'dd-1', 'time' => '8:15 AM']);
    }

    public function test_drilldown_for_all_tsas_combines_the_whole_hours_orders(): void
    {
        $shifts = TsaShift::where('team', 'SH Naturals')->get();
        $this->order('dd-4', $shifts[0]->tsa_key, '2026-07-22 08:10:00', 'NOT ANSWERING');
        $this->order('dd-5', $shifts[1]->tsa_key, '2026-07-22 08:20:00', 'NOT ANSWERING');

        $response = $this->getJson(route('tsa-performance.drilldown', [
            'team' => 'sh-naturals', 'tsa' => '__all__', 'hour' => 8,
            'column' => 'not_answering', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    public function test_drilldown_with_no_hour_covers_the_whole_day_for_grand_total(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $this->order('dd-6', $shift->tsa_key, '2026-07-22 06:00:00', 'INVALID NUMBER');
        $this->order('dd-7', $shift->tsa_key, '2026-07-22 20:00:00', 'INVALID NUMBER');

        $response = $this->getJson(route('tsa-performance.drilldown', [
            'team' => 'sh-naturals', 'tsa' => '__all__',
            'column' => 'invalid_number', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    public function test_drilldown_for_upsell_confirmation_uses_the_real_upsell_flag_not_disposition_text(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $this->order('dd-8', $shift->tsa_key, '2026-07-22 08:00:00', 'CONFIRMED VIA CALL', isUpsell: true);
        $this->order('dd-9', $shift->tsa_key, '2026-07-22 08:00:00', 'CONFIRMED VIA CALL', isUpsell: false);

        $response = $this->getJson(route('tsa-performance.drilldown', [
            'team' => 'sh-naturals', 'tsa' => $shift->tsa_key, 'hour' => 8,
            'column' => 'upsell_confirmation', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains('dd-8'));
        $this->assertFalse($ids->contains('dd-9'));
    }

    public function test_drilldown_for_total_called_unions_every_disposition_column(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $this->order('dd-10', $shift->tsa_key, '2026-07-22 08:00:00', 'CONFIRMED VIA CALL');
        $this->order('dd-11', $shift->tsa_key, '2026-07-22 08:00:00', 'NOT ANSWERING');
        $this->order('dd-12', $shift->tsa_key, '2026-07-22 08:00:00', null); // uncalled — excluded

        $response = $this->getJson(route('tsa-performance.drilldown', [
            'team' => 'sh-naturals', 'tsa' => $shift->tsa_key, 'hour' => 8,
            'column' => 'total_called', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains('dd-10'));
        $this->assertTrue($ids->contains('dd-11'));
        $this->assertFalse($ids->contains('dd-12'));
    }
}
