<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\ProjectionColumn;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ProjectionCalculator;
use App\Support\ProjectionMonth;
use App\Support\TsaDailyTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Daily Target countdown (2026-11-27) — see App\Support\TsaDailyTarget. */
class TsaDailyTargetTest extends TestCase
{
    use RefreshDatabase;

    private function tsa(string $team): TsaShift
    {
        return TsaShift::where('team', $team)->firstOrFail();
    }

    private function userFor(TsaShift $tsa): User
    {
        return User::create(['name' => 'TSA', 'email' => "tsa{$tsa->id}@test.com", 'password' => bcrypt('x'),
            'is_active' => true, 'role' => 'normal', 'tsa_id' => $tsa->id]);
    }

    private function seedProjection(): void
    {
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
    }

    private function expectedDaily(string $key): float
    {
        $month = now()->format('Y-m');
        $columns = ProjectionColumn::where('month', $month)->orderBy('sort_order')->get();

        return ProjectionMonth::with($month, fn () => (float) ProjectionCalculator::forAllColumns($columns, ProjectionCalculator::allRates())[$key]['pnl']['gross_sales']);
    }

    private function upsellOrder(TsaShift $tsa, string $id, float $amount, array $extra = []): Order
    {
        return Order::create(array_merge([
            'pancake_order_id' => $id, 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'is_upsell' => true, 'is_returned_upsell' => false, 'amount' => $amount, 'status_code' => 1,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ], $extra));
    }

    public function test_no_target_when_projections_has_no_month(): void
    {
        ProjectionColumn::query()->delete();
        $tsa = $this->tsa('SH Naturals');

        $this->actingAs($this->userFor($tsa))->getJson(route('calls.tsa-status.own-target'))
            ->assertOk()->assertJson(['target' => null]);
    }

    public function test_opening_and_closing_teams_use_their_own_daily_target(): void
    {
        $this->seedProjection();

        $this->assertEqualsWithDelta($this->expectedDaily('opening_individual_tsa_daily'), TsaDailyTarget::for($this->tsa('SH Naturals'))['target'], 0.01);
        $this->assertEqualsWithDelta($this->expectedDaily('closing_individual_tsa_daily'), TsaDailyTarget::for($this->tsa('Eyecare Team'))['target'], 0.01);
    }

    public function test_confirmed_sales_lower_the_remaining_amount(): void
    {
        $this->seedProjection();
        $tsa = $this->tsa('SH Naturals');
        $this->upsellOrder($tsa, 'o-1', 800);
        $this->upsellOrder($tsa, 'o-2', 1200);
        // Not an upsell (cancelled before it counted) — ignored.
        $this->upsellOrder($tsa, 'o-3', 5000, ['is_upsell' => false]);

        $t = TsaDailyTarget::for($tsa);

        $this->assertSame(2000.0, $t['confirmed']);
        $this->assertSame(0.0, $t['pending']);
        $this->assertEqualsWithDelta($t['target'] - 2000, $t['remaining'], 0.01);
        $this->assertFalse($t['hit']);
    }

    public function test_an_in_app_upsell_counts_as_pending_until_its_order_syncs(): void
    {
        $this->seedProjection();
        $tsa = $this->tsa('SH Naturals');
        $order = $this->upsellOrder($tsa, 'o-9', 0, ['is_upsell' => false, 'synced_at' => now()->subMinutes(10)]);
        $lead = Lead::create(['pancake_order_id' => 'o-9', 'customer_name' => 'Juan', 'tsa_id' => $tsa->id, 'status' => 'assigned']);
        LeadActivity::log($lead, 'upsell_added', 'Added upsell', null, 900);

        $this->assertSame(900.0, TsaDailyTarget::for($tsa)['pending']);

        // Next sync picks up the confirmed upsell — no longer pending, now confirmed.
        $order->update(['is_upsell' => true, 'amount' => 900, 'synced_at' => now()->addMinute()]);
        $t = TsaDailyTarget::for($tsa);

        $this->assertSame(0.0, $t['pending']);
        $this->assertSame(900.0, $t['confirmed']);
    }

    public function test_target_hit_reports_amount_over(): void
    {
        $this->seedProjection();
        $tsa = $this->tsa('SH Naturals');
        $target = TsaDailyTarget::for($tsa)['target'];
        $this->upsellOrder($tsa, 'o-big', $target + 500);

        $t = TsaDailyTarget::for($tsa);

        $this->assertTrue($t['hit']);
        $this->assertSame(0.0, $t['remaining']);
        $this->assertEqualsWithDelta(500, $t['over'], 0.01);
        $this->assertSame(100, $t['percent']);
    }
}
