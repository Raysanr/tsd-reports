<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Individual TSA page: per-product leads, catered and rates (2026-11-07). */
class TsaIndividualProductSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_each_product_she_had_leads_for_with_catered_and_rates(): void
    {
        $this->actingAs(User::factory()->create());
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $order = fn (string $id, array $extra) => Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key, 'product' => 'Sinuxyl',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-11-03 16:00:00', 'synced_at' => now(),
        ]);
        $order('called', ['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => [strtoupper($tsa->tsa_key), 'CONFIRMED VIA CALL']]);
        $order('not-answering', ['disposition' => 'NOT ANSWERING', 'raw_tags' => [strtoupper($tsa->tsa_key), 'NOT ANSWERING']]);

        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'sh-naturals', 'tsaKey' => $tsa->tsa_key, 'date_from' => '2026-11-03', 'date_to' => '2026-11-03',
        ]))->assertOk()->assertSee('Per-Product Summary');

        $rows = $response->viewData('productSummaryRows');
        $this->assertCount(1, $rows);
        $row = $rows->first()['row'];
        $this->assertSame(2, $row['total']);
        $this->assertSame(2, $row['catered']);
        $this->assertEqualsWithDelta(50.0, $row['pick_up_rate'], 0.01);
        $this->assertSame(2, $response->viewData('productSummaryTotal')['total']);
    }

    /** 2026-11-10: a lead matching no product went missing (TOTAL 21 vs the
     *  hourly Day Total 22). It now shows as "Other", TOTAL equals the Day
     *  Total, and every count cell lists exactly its own order IDs. */
    public function test_unmatched_leads_show_as_other_and_each_cell_lists_its_orders(): void
    {
        $this->actingAs(User::factory()->create());
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $order = fn (string $id, array $extra) => Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key, 'product' => 'Sinuxyl',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-11-03 16:00:00', 'synced_at' => now(),
        ]);
        $tag = strtoupper($tsa->tsa_key);
        $order('sinuxyl-called', ['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => [$tag, 'CONFIRMED VIA CALL']]);
        $order('sinuxyl-uncalled', ['raw_tags' => [$tag]]);
        $order('mystery-called', ['product' => 'Totally Unknown Item', 'disposition' => 'CALL BACK', 'raw_tags' => [$tag, 'CALL BACK']]);

        $params = ['team' => 'sh-naturals', 'tsaKey' => $tsa->tsa_key, 'date_from' => '2026-11-03', 'date_to' => '2026-11-03'];
        $response = $this->get(route('tsa-performance.individual', $params))->assertOk()->assertSee('Other (no product match)');

        $other = $response->viewData('productSummaryOther');
        $this->assertSame(1, $other['total']);
        $this->assertSame(1, $other['catered']);
        $total = $response->viewData('productSummaryTotal');
        $this->assertSame(3, $total['total']);
        $this->assertSame($response->viewData('summary')['total_called'], $total['catered']);

        $ids = fn (array $query) => collect($this->getJson(route('tsa-performance.individual.product-drilldown', [
            'team' => 'sh-naturals', 'tsaKey' => $tsa->tsa_key,
        ]) . '?' . http_build_query($query + ['date_from' => '2026-11-03', 'date_to' => '2026-11-03']))
            ->assertOk()->json())->pluck('id')->sort()->values()->all();

        $sinuxyl = (string) $response->viewData('productSummaryRows')->first()['product']->id;
        $this->assertSame(['sinuxyl-called', 'sinuxyl-uncalled'], $ids(['product' => $sinuxyl, 'column' => 'total']));
        $this->assertSame(['sinuxyl-called'], $ids(['product' => $sinuxyl, 'column' => 'catered']));
        $this->assertSame(['mystery-called'], $ids(['product' => 'other', 'column' => 'total']));
        $this->assertSame(['mystery-called', 'sinuxyl-called', 'sinuxyl-uncalled'], $ids(['column' => 'total']));
        $this->assertSame(['mystery-called', 'sinuxyl-called'], $ids(['column' => 'catered']));
    }
}
