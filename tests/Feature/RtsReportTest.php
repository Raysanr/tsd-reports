<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // Coverage gap found while extending dark mode to the report pages (Part 2a):
    // rts-report.blade.php had zero Feature test coverage before this. Just
    // proves the page renders for an authenticated user; not a styling-specific
    // test.
    public function test_index_renders_successfully_for_an_authenticated_user(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertViewIs('rts-report');
    }

    // UI/UX review finding: a range with genuinely no RTS/Delivered activity
    // showed a wall of ₱0.00 across every TSA in both teams with nothing
    // distinguishing "nothing happened" from "something's wrong."
    public function test_shows_empty_state_when_no_rts_or_delivered_activity(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertSee('No RTS or Delivered upsells for', false);
        $response->assertDontSee('data-sortable-table', false);
    }

    public function test_shows_tables_instead_of_empty_state_when_delivered_activity_exists(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => 'rts-empty-state-test-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'CANPRO',
            'raw_tags'           => ['CANPRO', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => true,
            'status_code'        => 3,
            'amount'             => 499,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertDontSee('No RTS or Delivered upsells for', false);
        $response->assertSee('data-sortable-table', false);
    }
}
