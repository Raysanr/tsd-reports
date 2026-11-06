<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\TsaSalesReportController;
use App\Models\Order;
use App\Models\TsaSalesMonthLock;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A locked month keeps its saved Summary Sales Report figures. */
class TsaSalesMonthLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addOrder(): Order
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        return Order::create([
            'pancake_order_id' => 'october-order', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key, 'product' => 'Sinuxyl',
            'disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-10-15 10:00:00', 'synced_at' => now(),
        ]);
    }

    private function catered(): float
    {
        return (float) app(TsaSalesReportController::class)->overallByDate('2026-10-01', '2026-10-31')['range']['catered_leads'];
    }

    public function test_a_locked_month_keeps_its_figures_until_unlocked(): void
    {
        $order = $this->addOrder();
        $before = $this->catered();
        $this->assertGreaterThan(0, $before);

        $this->post(route('data.tsa-sales.lock-month'), ['month' => '2026-10'])->assertRedirect();
        $order->delete();

        $this->assertSame($before, $this->catered());

        $this->delete(route('data.tsa-sales.unlock-month'), ['month' => '2026-10'])->assertRedirect();
        $this->assertSame(0.0, $this->catered());
    }

    public function test_the_current_month_cannot_be_locked(): void
    {
        $this->post(route('data.tsa-sales.lock-month'), ['month' => '2026-11'])->assertStatus(422);
        $this->assertSame(0, TsaSalesMonthLock::count());
    }

    public function test_a_locked_month_refuses_edits(): void
    {
        $this->post(route('data.tsa-sales.lock-month'), ['month' => '2026-10']);
        $shift = TsaShift::first();

        $this->patchJson(route('data.tsa-sales.update-entry', [$shift, '2026-10-15']), ['ads_spent' => 5])
            ->assertStatus(422)->assertJson(['message' => 'This date is locked.']);
    }

    public function test_a_normal_user_cannot_lock_a_month(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']))
            ->post(route('data.tsa-sales.lock-month'), ['month' => '2026-10'])->assertForbidden();
    }

    public function test_the_page_offers_the_lock_for_a_past_month_only(): void
    {
        $this->get(route('data.tsa-sales', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))->assertOk()->assertSee('Lock October 2026');
        $this->get(route('data.tsa-sales', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']))->assertOk()->assertDontSee('Lock November 2026');
    }
}
