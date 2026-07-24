<?php

namespace Tests\Feature;

use App\Models\CallRecordingHour;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: OPT/Unproductive Time on the individual TSA page should
 * reflect real call durations (synced from Google Drive by SyncCallRecordings)
 * instead of the flat "3 minutes per answered call" assumption, wherever real
 * data has been synced for that TSA/date/hour. Hours with no synced recordings
 * yet must keep falling back to the original formula unchanged.
 */
class TsaPerformanceRealOptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Product::create(['display_name' => 'Test Product', 'team' => 'SH Naturals', 'sort_order' => 0]);
    }

    private function order(string $id, string $tsa, string $time, string $disposition): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => 'SH Naturals',
            'tsa_name'            => $tsa,
            'disposition'         => $disposition,
            'status_code'         => 1,
            'pancake_created_at'  => $time,
            'pancake_inserted_at' => $time,
            'synced_at'           => now(),
        ]);
    }

    public function test_hour_with_synced_recordings_shows_real_opt_and_aht_with_a_marker(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        $this->order('opt-1', $shift->tsa_key, '2026-07-22 08:15:00', 'CONFIRMED VIA CALL');

        CallRecordingHour::create([
            'tsa_key'       => $shift->tsa_key,
            'date'          => '2026-07-22',
            'hour'          => 8,
            'total_seconds' => 600,
            'call_count'    => 2,
        ]);

        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => $shift->tsa_key,
            'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertSee('5:00');  // AHT: 600s / 2 calls = 300s average
        $response->assertSee('●', false);
    }

    public function test_hour_with_no_synced_recordings_falls_back_to_the_formula_with_no_marker(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        $this->order('opt-2', $shift->tsa_key, '2026-07-22 09:15:00', 'CONFIRMED VIA CALL');

        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => $shift->tsa_key,
            'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertDontSee('●', false);
    }
}
