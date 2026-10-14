<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\ExpectedIncomeController;
use App\Models\ExpectedIncomeTiktokEntry;
use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-10-14): "when there's tiktok upsell it the tiktok
 * filter will be fall of data / so the tiktok will be automated too" —
 * orders tagged "TIKTOK UPSELL -NATUREVA" / "TIKTOK UPSELL -SH NATURALS"
 * (e.g. real orders #1376222/#1376266, Anne, Oct 4) fill the TikTok cards'
 * Gross Sales / Number of Orders / Cancelled automatically, and never count
 * on a regular product/team figure too.
 */
class TiktokUpsellAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function tiktokOrder(TsaShift $tsa, string $id, string $date, float $amount, string $tag = 'TIKTOK UPSELL -NATUREVA', int $status = 2): Order
    {
        return Order::create([
            'pancake_order_id' => $id,
            'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'Haplunas Balm',
            'is_upsell' => $status !== 6, 'is_upsell_on_voided_order' => $status === 6, 'amount' => $amount, 'status_code' => $status,
            'raw_tags' => [strtoupper($tsa->tsa_key), 'Unreconciled', 'SEPARATE PARCEL', $tag],
            'pancake_created_at' => $date . ' 19:32:22', 'synced_at' => now(),
        ]);
    }

    public function test_detects_both_tiktok_tags_loosely(): void
    {
        $this->assertSame('natureva', Order::tiktokCardKey(['ANNE', 'TIKTOK UPSELL -NATUREVA']));
        $this->assertSame('natureva', Order::tiktokCardKey(['tiktok upsell - natureva']));
        $this->assertSame('sh_naturals', Order::tiktokCardKey(['TIKTOK UPSELL -SH NATURALS']));
        $this->assertSame('sh_naturals', Order::tiktokCardKey(['TIKTOK UPSELL - SH NATURALS']));
        $this->assertNull(Order::tiktokCardKey(['UPSELL TSD - NUTRILAY + NUTRIGAIN', 'SEPARATE PARCEL']));
    }

    public function test_tiktok_card_figures_come_from_tagged_orders(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $this->tiktokOrder($tsa, '1376222', $date, 500);
        $this->tiktokOrder($tsa, '1376266', $date, 500);
        $this->tiktokOrder($tsa, 'sh-1', $date, 300, 'TIKTOK UPSELL -SH NATURALS');

        // A stale manually-typed figure must never win over the POS total.
        ExpectedIncomeTiktokEntry::create(['card_key' => 'natureva', 'tsa_id' => $tsa->id, 'entry_date' => $date, 'gross_sales' => 9999, 'number_of_orders' => 9]);

        $totals = ExpectedIncomeController::tiktokTotalsByTsaAndDate($date, $date)->get($tsa->id . ':' . $date);

        $this->assertEqualsWithDelta(1300.0, $totals['gross_sales'], 0.01, 'both cards summed: 500 + 500 Natureva + 300 SH Naturals');
        $this->assertSame(3, $totals['total_orders']);
    }

    public function test_a_cancelled_tiktok_order_lands_in_cancelled(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $this->tiktokOrder($tsa, 'tt-ok', $date, 500);
        $this->tiktokOrder($tsa, 'tt-cancelled', $date, 400, status: 6);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'natureva', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['product_cost' => 10]
        )->assertOk();

        $this->assertEqualsWithDelta(400.0, $response->json('derived.cancelled'), 0.01);
    }

    public function test_manual_edits_to_automated_fields_are_ignored(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();
        $this->tiktokOrder($tsa, '1376222', $date, 500);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(
            route('data.expected-income.update-tiktok', ['cardKey' => 'natureva', 'tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 7777, 'number_of_orders' => 5, 'cancelled' => 100]
        )->assertOk();

        $this->assertEqualsWithDelta(500.0, $response->json('derived.gross_sales'), 0.01);
        $this->assertSame(1, (int) $response->json('derived.number_of_orders'));
        $this->assertEqualsWithDelta(0.0, $response->json('derived.cancelled'), 0.01);
    }

    public function test_telesales_on_all_counts_a_tiktok_order_exactly_once(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();
        $this->tiktokOrder($tsa, '1376222', $date, 500);

        $content = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.expected-income', ['date_from' => $date, 'date_to' => $date, 'team' => 'all']))
            ->assertOk()->getContent();

        $pos = strpos($content, '>TELESALES<');
        $next = strpos($content, 'class="ei-card', $pos + 500);
        $card = substr($content, $pos, ($next ?: $pos + 20000) - $pos);
        preg_match('/data-out="gross_sales"[^>]*>\s*([\d,.]+)/', $card, $m);

        $this->assertEqualsWithDelta(500.0, (float) str_replace(',', '', $m[1]), 0.01, 'the TikTok order must count once (TikTok fold-in), not also via the team total');
    }

    public function test_tiktok_card_renders_automated_fields_read_only(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();

        $content = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.expected-income', ['date_from' => $date, 'date_to' => $date, 'team' => 'tiktok']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-tiktok-card="1"', $content);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-field="gross_sales"/s', $content);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-field="number_of_orders"/s', $content);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-field="cancelled"/s', $content);
    }
}
