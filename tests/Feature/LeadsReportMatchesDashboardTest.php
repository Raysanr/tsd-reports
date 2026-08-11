<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-11, "accurate from POS"): proves the actual live
 * report is fixed, not just a unit-level assertion — Leads Report showed 256
 * new leads for Eyecare/2026-08-10 against Dashboard's 255. Both now filter
 * by COALESCE(pancake_inserted_at, pancake_created_at) (see
 * DashboardTotalLeadsEffectiveDateTest's class doc for the full mechanism),
 * so this seeds the exact backlog shape (order created one day, worked the
 * next) that caused the live disagreement and checks both pages agree.
 */
class LeadsReportMatchesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_leads_report_and_dashboard_agree_on_a_day_with_a_backlog_order(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();
        $today = now()->toDateString();

        // Worked today, created today — the ordinary case both pages should
        // already agree on. Leads Report's Grand Total sums PER-PRODUCT matched
        // rows (see LeadsReportGrandTotalTest), so both orders need a real
        // product tag, not just team/date — 'Pterygium' matches the same way
        // that test already proves works for Eyecare.
        Order::factory()->create([
            'pancake_order_id' => 'today-1', 'team' => 'Eyecare Team', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_inserted_at' => now(), 'pancake_created_at' => now(),
            'synced_at' => now(),
        ]);

        // The exact backlog shape that caused the live 255-vs-256 gap: created
        // yesterday, worked today. Before this fix, Dashboard/TSA Performance
        // counted it toward TODAY (pancake_created_at=today) while Leads Report
        // did not (pancake_inserted_at=yesterday) — that one-order disagreement
        // IS the live report. Now both correctly exclude it from today, same
        // convention, same answer.
        Order::factory()->create([
            'pancake_order_id' => 'backlog-1', 'team' => 'Eyecare Team', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'NOT ANSWERING', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($shift->tsa_key), 'NOT ANSWERING'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_inserted_at' => now()->subDay(), 'pancake_created_at' => now(),
            'synced_at' => now(),
        ]);

        $dashboard = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));
        $dashboard->assertOk();
        $dashboardTotal = $dashboard->viewData('stats')['total_leads'];

        $leadsReport = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));
        $leadsReport->assertOk();
        $leadsReportTotal = $leadsReport->viewData('grandTotal')['total'];

        $this->assertSame($dashboardTotal, $leadsReportTotal);
        // Only today-1 — backlog-1 correctly excluded from today on both pages.
        $this->assertSame(1, $leadsReportTotal);
    }
}
