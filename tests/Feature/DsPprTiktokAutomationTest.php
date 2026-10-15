<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\ExpectedIncomeController;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request (2026-10-15): "in the dsppr make this automated too the
 *  tiktok that is reflected by expected income". */
class DsPprTiktokAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function setUpTiktokTsa(): TsaShift
    {
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);

        return $tsa;
    }

    public function test_tiktok_orders_row_mirrors_expected_income_and_ignores_manual_edits(): void
    {
        $tsa = $this->setUpTiktokTsa();
        $date = today()->toDateString();
        $this->seedTiktokOrder($tsa->id, 'natureva', $date, 1000);
        $this->seedTiktokOrder($tsa->id, 'sh_naturals', $date, 500);

        $expected = ExpectedIncomeController::tiktokTotalsByTsaAndDate($date, $date)->get($tsa->id . ':' . $date);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(
            route('data.dsppr.update-tiktok', ['date' => $date]),
            ['gross_sales' => 99999, 'net_income' => 12345, 'total_orders' => 77, 'total_leads' => 9]
        )->assertOk();

        $this->assertEqualsWithDelta(1500.0, $response->json('derived.gross_sales'), 0.01);
        $this->assertEqualsWithDelta($expected['net_income'], $response->json('derived.net_income'), 0.01);
        $this->assertSame(2, (int) $response->json('derived.total_orders'));
        $this->assertSame(9, (int) $response->json('derived.total_leads'), 'leads stay manual');
    }

    public function test_automated_fields_render_read_only_but_pre_oct_1_stays_editable(): void
    {
        $this->setUpTiktokTsa();
        $admin = User::factory()->create(['role' => 'admin']);
        $today = today()->toDateString();

        $content = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $today, 'date_to' => $today]))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-field="gross_sales"[^>]*data-date="' . $today . '"/s', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*data-field="total_leads"[^>]*data-date="' . $today . '"/s', $content);

        $before = '2026-09-30';
        $content = $this->actingAs($admin)->get(route('data.dsppr', ['date_from' => $before, 'date_to' => $before]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*data-field="gross_sales"[^>]*data-date="' . $before . '"/s', $content);
    }
}
