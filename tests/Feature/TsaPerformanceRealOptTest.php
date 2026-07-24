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

    /**
     * Bug found by inspecting real synced data: an hour with 5 answered calls but
     * only 1 synced recording was showing OPT=0.6min (that one call's duration) as
     * if it were the whole hour's total, wildly understating it. OPT/AHT must blend
     * the real duration with a 3-min estimate for the answered calls that don't
     * have a matching recording yet, not just report the partial sample outright.
     */
    public function test_partial_recording_coverage_blends_real_duration_with_the_estimate_for_the_rest(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        // 5 answered calls this hour...
        for ($i = 1; $i <= 5; $i++) {
            $this->order("blend-{$i}", $shift->tsa_key, "2026-07-22 08:{$i}5:00", 'CONFIRMED VIA CALL');
        }
        // ...but only 1 of them has a synced recording (37 seconds).
        CallRecordingHour::create([
            'tsa_key'       => $shift->tsa_key,
            'date'          => '2026-07-22',
            'hour'          => 8,
            'total_seconds' => 37,
            'call_count'    => 1,
        ]);

        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => $shift->tsa_key,
            'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        // blended = 37s + 4 unmatched x 180s = 757s -> OPT = 12.6min, AHT = 757/5 = 151s = 2:31
        $response->assertSee('12.6');
        $response->assertSee('2:31');
    }
}
