<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-10-15): eye icon in TSA Management hides a TSA on
 * every page "even she has data" — chosen scope: hide her ROW only (her
 * orders still count in every total), display only (login/lead assignment
 * untouched).
 */
class HiddenTsaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function hide(string $tsaKey): TsaShift
    {
        $tsa = TsaShift::where('tsa_key', $tsaKey)->firstOrFail();
        $tsa->update(['is_hidden' => true]);

        return $tsa;
    }

    public function test_eye_toggle_flips_the_flag(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        $url = route('tsa-management.toggle-hidden', $gemma);

        $this->actingAs($this->admin())->postJson($url)->assertOk()->assertJson(['hidden' => true]);
        $this->assertTrue($gemma->fresh()->is_hidden);

        $this->postJson($url)->assertOk()->assertJson(['hidden' => false]);
        $this->assertFalse($gemma->fresh()->is_hidden);
    }

    public function test_normal_user_cannot_toggle(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'normal']))
            ->post(route('tsa-management.toggle-hidden', $gemma))->assertForbidden();
        $this->assertFalse($gemma->fresh()->is_hidden);
    }

    public function test_tsa_performance_hides_her_row_but_keeps_her_in_the_grand_total(): void
    {
        $date = '2026-10-15';
        foreach (['Gemma', 'Joana'] as $i => $key) {
            $tsa = TsaShift::where('tsa_key', $key)->firstOrFail();
            Order::factory()->create([
                'pancake_order_id' => "hid-{$i}", 'team' => $tsa->team, 'tsa_name' => $key,
                'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'],
                'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
                'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
            ]);
        }
        $params = ['team' => 'all', 'date_from' => $date, 'date_to' => $date];
        $this->actingAs($this->admin());

        $before = $this->get(route('tsa-performance', $params))->viewData('grandTotal');
        $this->hide('Gemma');
        $response = $this->get(route('tsa-performance', $params))->assertOk();

        $this->assertNotContains('Gemma', $response->viewData('tsaRows')->pluck('tsa_key')->all());
        $this->assertContains('Joana', $response->viewData('tsaRows')->pluck('tsa_key')->all());
        $this->assertSame($before, $response->viewData('grandTotal'), 'totals must not change');
        $this->assertSame(2, $response->viewData('grandTotal')['total']);
    }

    public function test_call_tracker_pages_drop_her_row(): void
    {
        $gemma = $this->hide('Gemma');
        $this->actingAs($this->admin());

        $this->assertFalse($this->get(route('calls.monitor'))->assertOk()->viewData('tsas')->contains('id', $gemma->id));
        $this->assertFalse($this->get(route('calls.dashboard'))->assertOk()->viewData('tsas')->contains('id', $gemma->id));
        $this->assertFalse($this->get(route('calls.call-log'))->assertOk()->viewData('teamTsas')->contains('id', $gemma->id));
        $this->assertFalse($this->get(route('calls.analytics'))->assertOk()->viewData('rows')->contains(fn ($r) => $r['tsa']->id === $gemma->id));
        $this->assertFalse($this->get(route('calls.tsa-logs'))->assertOk()->viewData('tsas')->contains('id', $gemma->id));
    }

    public function test_hiding_is_display_only(): void
    {
        $gemma = $this->hide('Gemma');

        $this->assertTrue($gemma->fresh()->active, 'she stays active, so login/round-robin are untouched');
    }

    public function test_summary_sales_keeps_her_row_in_the_dom_but_hidden(): void
    {
        $gemma = $this->hide('Gemma');

        $html = $this->actingAs($this->admin())
            ->get(route('data.tsa-sales', ['date_from' => '2026-10-15', 'date_to' => '2026-10-15']))
            ->assertOk()->getContent();

        // Kept (with `hidden`) so the JS totals that sum every row still include her.
        $this->assertMatchesRegularExpression('/class="tsr-row hidden [^"]*"[^>]*data-tsa-id="' . $gemma->id . '"/', $html);
    }
}
