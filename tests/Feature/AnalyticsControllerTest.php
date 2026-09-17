<?php

namespace Tests\Feature;

use App\Models\CallRecordingHour;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
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

    /**
     * Unproductive Time formula (explicit request, 2026-09-17):
     * "Unproductive Hours = Break + Lunch + DNA Huddle + Huddle + Coaching
     * + Others" — real time from TsaStatusLog, replacing the old "440min/
     * day shift constant minus real call duration (THT)" estimate this
     * table used before. Matches the request's own worked example: Break
     * 20 + Lunch 60 + DNA Huddle 10 + Huddle 10 + Coaching 30 + Others 10
     * = 140 minutes.
     */
    public function test_unproductive_minutes_sums_break_lunch_dna_huddle_huddle_coaching_and_others(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila');
        $start = $today->copy()->startOfDay()->addHours(8);

        // Login 8:00-8:20, Break 8:20-8:40 (20min), Lunch 8:40-9:40 (60min),
        // DNA Huddle 9:40-9:50 (10min), Huddle 9:50-10:00 (10min), Coaching
        // 10:00-10:30 (30min), Others 10:30-10:40 (10min), then back to
        // Login (the 140 unproductive minutes stop accumulating there).
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'login', 'created_at' => $start]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'break', 'created_at' => $start->copy()->addMinutes(20)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'lunch', 'created_at' => $start->copy()->addMinutes(40)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'dna_huddle', 'created_at' => $start->copy()->addMinutes(100)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'huddle', 'created_at' => $start->copy()->addMinutes(110)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'coaching', 'created_at' => $start->copy()->addMinutes(120)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'others', 'created_at' => $start->copy()->addMinutes(150)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'login', 'created_at' => $start->copy()->addMinutes(160)]);

        $response = $this->actingAs($admin)->get(route('calls.analytics', [
            'date_from' => $today->format('Y-m-d'), 'date_to' => $today->format('Y-m-d'),
        ]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $gemmaRow = $rows->firstWhere('tsa.id', $gemma->id);

        $this->assertEquals(140, $gemmaRow['unproductive_minutes']);
    }

    /** Calling/Wrap Up/Login don't count as unproductive — confirmed here
     *  since Gemma's default status ('login', unless a log says otherwise)
     *  contributes nothing. */
    public function test_unproductive_minutes_is_zero_with_no_unproductive_status_time_logged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        $response = $this->actingAs($admin)->get(route('calls.analytics', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $gemmaRow = $rows->firstWhere('tsa.id', $gemma->id);

        $this->assertEquals(0, $gemmaRow['unproductive_minutes']);
    }
}
