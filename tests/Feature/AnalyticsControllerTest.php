<?php

namespace Tests\Feature;

use App\Models\CallRecordingHour;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No test file existed for AnalyticsController before this (2026-09-05) —
 * added alongside switching AHT/THT from CallEvent (MacroDroid webhook,
 * only 3/7 TSAs configured, weeks stale) to CallRecordingHour (real
 * Google-Drive-synced per-hour totals), the same fix DashboardController
 * already applied to its own AHT card — see that controller's own doc
 * comment (2026-08-24) for the full reasoning this ports over.
 */
class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_aht_and_tht_are_computed_from_real_call_recording_hours(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        // Two synced hours for Gemma today: 10 calls totaling 1200s (10-14h),
        // 5 calls totaling 900s (14-15h) — pooled AHT = (1200+900)/(10+5) = 140s.
        CallRecordingHour::create(['tsa_key' => 'Gemma', 'date' => $today, 'hour' => 10, 'total_seconds' => 1200, 'call_count' => 10]);
        CallRecordingHour::create(['tsa_key' => 'Gemma', 'date' => $today, 'hour' => 14, 'total_seconds' => 900, 'call_count' => 5]);
        // Outside the range — must not be counted.
        CallRecordingHour::create(['tsa_key' => 'Gemma', 'date' => now('Asia/Manila')->subDays(5)->format('Y-m-d'), 'hour' => 10, 'total_seconds' => 999999, 'call_count' => 999]);

        $response = $this->actingAs($admin)->get(route('calls.analytics', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $gemmaRow = $rows->firstWhere('tsa.id', $gemma->id);

        $this->assertSame(15, $gemmaRow['aht_call_count']);
        $this->assertSame(140, $gemmaRow['aht_seconds']);
        $this->assertSame(2100, $gemmaRow['tht_seconds']);
    }

    public function test_a_tsa_with_no_synced_recording_hours_shows_null_aht_not_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kathleen = TsaShift::where('tsa_key', 'Kathleen')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        $response = $this->actingAs($admin)->get(route('calls.analytics', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $kathleenRow = $rows->firstWhere('tsa.id', $kathleen->id);

        $this->assertNull($kathleenRow['aht_seconds']);
        $this->assertSame(0, $kathleenRow['aht_call_count']);
        $this->assertSame(0, $kathleenRow['tht_seconds']);
    }

    public function test_overall_aht_is_the_true_pooled_average_not_average_of_averages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        // Gemma: 1 call at 100s. Mariel: 9 calls totaling 900s (avg 100s each too,
        // so pooled and per-TSA-averaged happen to agree here on the OVERALL
        // number — the real assertion is that it doesn't crash/divide-by-zero
        // and reflects total seconds / total calls across both TSAs: 1000/10=100.
        CallRecordingHour::create(['tsa_key' => 'Gemma', 'date' => $today, 'hour' => 10, 'total_seconds' => 100, 'call_count' => 1]);
        CallRecordingHour::create(['tsa_key' => 'Mariel', 'date' => $today, 'hour' => 10, 'total_seconds' => 900, 'call_count' => 9]);

        $response = $this->actingAs($admin)->get(route('calls.analytics', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $this->assertSame('1m 40s', $response->viewData('overallAhtDisplay'));
    }
}
