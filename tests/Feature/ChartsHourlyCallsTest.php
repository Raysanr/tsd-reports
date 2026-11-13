<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2026-11-13: Analytics' Answered vs Unanswered by Hour line chart. */
class ChartsHourlyCallsTest extends TestCase
{
    use RefreshDatabase;

    public function test_answered_and_unanswered_are_counted_per_hour(): void
    {
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $date = today()->toDateString();
        $order = fn (string $id, string $time, string $disposition) => Order::create([
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key, 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key), $disposition], 'disposition' => $disposition,
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => "{$date} {$time}", 'synced_at' => now(),
        ]);
        $order('a1', '09:10:00', 'CONFIRMED VIA CALL');
        $order('u1', '09:40:00', 'NOT ANSWERING');
        $order('u2', '09:50:00', 'UNATTENDED');
        $order('a2', '15:05:00', 'CALL BACK');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('charts', ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->assertSee('Answered vs Unanswered by Hour')->assertSee('hourlyCallsChart');

        $labels = $response->viewData('hourlyLabels');
        $answered = array_combine($labels, $response->viewData('hourlyAnswered'));
        $unanswered = array_combine($labels, $response->viewData('hourlyUnanswered'));
        $nine = \App\Support\HourFormatter::label(9);
        $three = \App\Support\HourFormatter::label(15);

        $this->assertSame([1, 2], [$answered[$nine], $unanswered[$nine]]);
        $this->assertSame([1, 0], [$answered[$three], $unanswered[$three]]);
    }
}
