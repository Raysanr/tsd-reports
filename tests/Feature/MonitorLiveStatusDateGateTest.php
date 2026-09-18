<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-24): "Current status time", the live status
 * badge, and End Call only make sense for TODAY — $tsa->status/
 * status_changed_at is always the TSA's live, right-now status, with no
 * concept of "what were they doing on Aug 20". Showing it next to a past
 * day's minute-record data read as if it belonged to that day. Viewing any
 * date other than today now shows only name/team + the minute-record
 * breakdown.
 */
class MonitorLiveStatusDateGateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_todays_view_shows_the_live_status_badge_and_current_status_time(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('Current status time');
    }

    public function test_a_past_dates_view_hides_the_live_status_badge_and_current_status_time(): void
    {
        // A TSA currently Calling — the End Call form only ever renders for
        // that status, so this proves the date-gate suppresses it even when
        // its own status condition would otherwise be satisfied.
        TsaShift::where('tsa_key', 'Gemma')->update(['status' => TsaShift::STATUS_CALLING, 'status_changed_at' => now()]);
        $yesterday = now('Asia/Manila')->subDay()->toDateString();

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => $yesterday, 'date_to' => $yesterday,
        ]));

        $response->assertOk();
        $response->assertDontSee('Current status time');
        // Not assertDontSee('End Call')/'monitor-end-call-form' — both
        // strings are also part of the page's own inline JS (a delegated
        // submit listener targeting that class, and the always-present
        // click-to-call modal's own button), so a raw substring search
        // matches the SCRIPT reference regardless of whether any actual
        // form with that class rendered. The form itself is nested INSIDE
        // the same @if($isSingleDay && $dateFrom->isToday()) block as
        // "Current status time" above (see monitor/_content.blade.php) —
        // proven hidden there is proven hidden here too.
    }

    public function test_a_multi_day_range_also_hides_the_live_status_badge(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => now('Asia/Manila')->subDays(2)->toDateString(),
            'date_to'   => now('Asia/Manila')->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('Current status time');
    }

    public function test_a_past_dates_view_still_shows_the_minute_record_breakdown(): void
    {
        $yesterday = now('Asia/Manila')->subDay()->toDateString();

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => $yesterday, 'date_to' => $yesterday,
        ]));

        $response->assertOk();
        $response->assertSee('Total tracked');
        $response->assertSee(TsaShift::where('tsa_key', 'Gemma')->first()->display_name);
    }

    /**
     * TSA Call Queue (explicit request, 2026-09-18) — a flat name/status/
     * minutes table right under the status-count cards. Same date-gate
     * reasoning as "Current status time" above — live $tsa->status has no
     * meaning for a past day, so this table only renders for today.
     */
    public function test_todays_view_shows_the_tsa_call_queue_table(): void
    {
        TsaShift::where('tsa_key', 'Gemma')->update(['status' => TsaShift::STATUS_CALLING, 'status_changed_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('TSA Call Queue');
        // "Calling" (title case, CSS uppercase is visual only via
        // tracking-wide/uppercase classes) — same real label
        // TsaShift::STATUSES['calling']['label'] returns everywhere else
        // on this page, not a literal all-caps string in the markup.
        $response->assertSeeInOrder([
            TsaShift::where('tsa_key', 'Gemma')->first()->display_name,
            'Calling',
        ]);
    }

    public function test_a_past_dates_view_hides_the_tsa_call_queue_table(): void
    {
        $yesterday = now('Asia/Manila')->subDay()->toDateString();

        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => $yesterday, 'date_to' => $yesterday,
        ]));

        $response->assertOk();
        $response->assertDontSee('TSA Call Queue');
    }

    public function test_a_multi_day_range_also_hides_the_tsa_call_queue_table(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.monitor', [
            'date_from' => now('Asia/Manila')->subDays(2)->toDateString(),
            'date_to'   => now('Asia/Manila')->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('TSA Call Queue');
    }
}
