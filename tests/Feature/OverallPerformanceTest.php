<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\OverallPerformanceController;
use App\Models\ProjectionColumn;
use App\Models\User;
use App\Support\DataSet;
use App\Support\ExpectedIncomeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TSD Overall Performance (2026-11-04): SH E-COM and Partners pages + sidebar groups. */
class OverallPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DataSet::reset();
        parent::tearDown();
    }

    private function migratePartners(): void
    {
        $this->artisan('migrate', ['--database' => DataSet::PARTNERS]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_sees_all_three_overall_pages(): void
    {
        $this->migratePartners();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('data.overall.projections', ['month' => '2026-11']))
            ->assertOk()->assertSee('Telesales Expected Performance')->assertSee('SH ECOMMERCE')
            ->assertSee('PEAK.CO')->assertDontSee("Partners data couldn't be loaded");

        $this->actingAs($admin)->get(route('data.overall.sales-summary', ['date_from' => '2026-11-01', 'date_to' => '2026-11-03']))
            ->assertOk()->assertSee('SH ECOMMERCE')->assertSee('PEAK ENTERPRISE')->assertSee('Partners IB.OPEX')->assertSee('Tue, Nov 3');

        $this->actingAs($admin)->get(route('data.overall.expected-income', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']))
            ->assertOk()->assertSee('TSD SH ECOMMERCE : EXPECTED PERFORMANCE')
            ->assertSee('TELESALES PARTNERSHIP : EXPECTED PERFORMANCE')
            ->assertSee('Expected Shared Income');
    }

    /** Sheet layout (2026-11-04): black TSD OVERALL, yellow SH E-COM, yellow
     *  TELESALES PARTNERSHIP, then each partner; partner cards list only
     *  Shipping Fee + COD Fee under Selling. */
    public function test_expected_income_matches_the_sheet_layout(): void
    {
        $this->migratePartners();

        $content = $this->actingAs($this->admin())
            ->get(route('data.overall.expected-income', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']))
            ->assertOk()->getContent();

        $overall = strpos($content, 'TSD OVERALL : EXPECTED PERFORMANCE');
        $sh = strpos($content, 'TSD SH ECOMMERCE : EXPECTED PERFORMANCE');
        $partners = strpos($content, 'TELESALES PARTNERSHIP : EXPECTED PERFORMANCE');
        $this->assertNotFalse($overall);
        $this->assertTrue($overall < $sh && $sh < $partners);

        // Only the two TSD-shape cards (TSD OVERALL + SH E-COM) show Advertising Cost.
        // Snapshot targets only the 3 overall cards; partner cards sit outside it.
        $this->assertStringContainsString('data-export-png="overallIncomeTopCards"', $content);
        $top = substr($content, strpos($content, 'id="overallIncomeTopCards"'));
        $this->assertTrue(strpos($top, 'TELESALES PARTNERSHIP') < strpos($top, 'PEAK.CO'));

        $this->assertSame(2, substr_count($content, 'data-row-key="advertising_cost"'));
        $this->assertGreaterThan(2, substr_count($content, 'data-row-key="shipping_fee"'));
    }

    /** Tally Checker (2026-11-04): both sums are listed and tally. */
    public function test_expected_income_tally_checker_lists_both_sums(): void
    {
        $this->migratePartners();

        $content = $this->actingAs($this->admin())
            ->get(route('data.overall.expected-income', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']))
            ->assertOk()
            ->assertSee('id="overallTallyBtn"', false)
            ->assertSee('TSD OVERALL = SH E-COM + TELESALES PARTNERSHIP')
            ->assertSee('TELESALES PARTNERSHIP = PEAK.CO')
            ->getContent();

        // The partners test DB keeps TSD's migration-seeded TSAs (no partner
        // team): their costs sit on TELESALES PARTNERSHIP only, so the
        // checker flags it and names them.
        $this->assertStringContainsString('data-tally-row="mismatch"', $content);
        $this->assertStringContainsString('data-tally-note', $content);
        $this->assertStringContainsString('(SH Naturals)', $content);

        DataSet::onPartners(fn () => \App\Models\TsaShift::query()->forceDelete());

        $content = $this->actingAs($this->admin())
            ->get(route('data.overall.expected-income', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']))
            ->assertOk()->getContent();
        $this->assertSame(20, substr_count($content, 'data-tally-row="ok"'));
        $this->assertStringNotContainsString('data-tally-row="mismatch"', $content);
        $this->assertStringNotContainsString('data-tally-note', $content);
    }

    /** The sheet's TELESALES DEPARTMENT card: SH E-COM lines, whole tax and
     *  operating costs, partners only as TSD's share. */
    public function test_tsd_overall_follows_the_sheet_and_keeps_the_final_net_income(): void
    {
        $this->migratePartners();
        $tsd = ExpectedIncomeCalculator::derive(['number_of_orders' => 61, 'gross_sales' => 37507, 'tax_allocation' => 1041.67, 'salaries' => 7000]);
        $partners = DataSet::onPartners(function () {
            \App\Models\Setting::set(ExpectedIncomeCalculator::PARTNERSHIP_SHARE_RATE_SETTING, 0.7);

            return ExpectedIncomeCalculator::derive(['number_of_orders' => 68, 'gross_sales' => 51105, 'tax_allocation' => 3125, 'salaries' => 11000]);
        });

        $build = new \ReflectionMethod(OverallPerformanceController::class, 'tsdOverallIncome');
        $card = $build->invoke(new OverallPerformanceController, $tsd, $partners, 0.7);

        $this->assertSame(37507.0, (float) $card['gross_sales']);
        $this->assertEqualsWithDelta(4166.67, $card['tax_allocation'], 0.001);
        $this->assertEqualsWithDelta($tsd['income_before_opex'] - 3125, $card['income_before_opex'], 0.001);
        $this->assertEqualsWithDelta(18000.0, collect($card['operating_lines'])['salaries'], 0.001);
        $this->assertEqualsWithDelta($partners['shared_income'], $card['partnership_income'], 0.001);
        $this->assertEqualsWithDelta($card['income_before_opex'] - $card['total_operating_costs'], $card['tsd_net_income'], 0.001);
        $this->assertEqualsWithDelta($tsd['net_income'] + $partners['net_income'], $card['net_income'], 0.001);
        $this->assertSame('TSD Partnership 70% IB.OPEX', $card['partnership_label']);
    }

    public function test_projections_total_sums_sh_ecom_and_each_partner(): void
    {
        $this->migratePartners();
        $admin = $this->admin();
        ProjectionColumn::ensureSeededForMonth('2026-11');
        ProjectionColumn::where('month', '2026-11')->where('key', 'opening_shift')->update(['orders_override' => 10, 'average_order_value' => 1000]);
        ProjectionColumn::where('month', '2026-11')->where('key', 'closing_shift')->update(['orders_override' => 0, 'average_order_value' => 1000]);
        DataSet::onPartners(function () {
            ProjectionColumn::ensureSeededForMonth('2026-11');
            $key = array_key_first(ProjectionColumn::shiftKeyLabels());
            ProjectionColumn::where('month', '2026-11')->where('key', $key)->update(['orders_override' => 5, 'average_order_value' => 1000]);
        });

        $content = $this->actingAs($admin)->get(route('data.overall.projections', ['month' => '2026-11']))->assertOk()->getContent();

        // Gross Sales: SH E-COM 10,000 + first partner 5,000 = 15,000 on the total card.
        $this->assertStringContainsString('15,000.00', $content);
        $this->assertStringContainsString('10,000.00', $content);
        $this->assertStringContainsString('5,000.00', $content);
    }

    public function test_pages_still_render_when_partners_database_is_missing(): void
    {
        $this->actingAs($this->admin())->get(route('data.overall.projections'))
            ->assertOk()->assertSee('SH ECOMMERCE')->assertSee("Partners data couldn't be loaded", false);
    }

    public function test_normal_users_cannot_open_overall_pages_or_see_the_group(): void
    {
        $user = User::factory()->create(['role' => 'normal']);

        $this->actingAs($user)->get(route('data.overall.projections'))->assertForbidden();
        $this->actingAs($user)->get(route('data.projections'))->assertOk()->assertSee('SH E-COM')->assertDontSee('TSD Overall Performance');
    }

    public function test_sidebar_shows_both_groups_for_admins(): void
    {
        $this->actingAs($this->admin())->get(route('data.projections'))
            ->assertOk()->assertSee('SH E-COM')->assertSee('TSD Overall Performance')
            ->assertSee('Sales Summary (SH E-COM and Partners)')->assertSee('Expected Income (SH E-COM and Partners)');
    }

    public function test_overall_pages_do_not_exist_under_partners(): void
    {
        $this->migratePartners();

        $this->actingAs($this->admin())->get('/partners/overall/projections')->assertNotFound();
    }

    /** Excess = Total - Catered - Restocking, per team, as on Leads Report. */
    public function test_sales_summary_counts_excess_leads_like_leads_report(): void
    {
        $this->migratePartners();
        $shift = \App\Models\TsaShift::where('team', 'SH Naturals')->first();
        $order = fn (string $id, array $extra) => \App\Models\Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key, 'product' => 'Sinuxyl',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-11-02 10:00:00', 'synced_at' => now(),
        ]);
        $order('called', ['disposition' => 'CONFIRMED VIA CALL', 'raw_tags' => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL']]);
        $order('uncalled', ['raw_tags' => []]);

        $this->actingAs($this->admin());
        $report = app(\App\Http\Controllers\DataManagement\TsaSalesReportController::class)->overallByDate('2026-11-02', '2026-11-02');
        $summary = \App\Support\OverallSalesSummary::build($report, []);

        $this->assertSame(1.0, (float) $summary['rows'][0]['days']['2026-11-02']['excess_leads']);
        $this->assertSame(1.0, (float) $summary['total']['range']['excess_leads']);
    }

    public function test_an_admin_can_save_and_clear_the_number_of_tsas(): void
    {
        $this->migratePartners();
        $save = fn (string $department, ?string $value) => $this->actingAs($this->admin())->patchJson(
            route('data.overall.sales-summary.tsa-count'),
            ['date' => '2026-11-01', 'department' => $department, 'value' => $value]
        );

        $save('SH E-COM', '2/4')->assertOk()->assertJson(['value' => '2/4']);
        $save('M&J', ' 1 / 3 ')->assertOk()->assertJson(['value' => '1/3']);

        $this->get(route('data.overall.sales-summary', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']))
            ->assertOk()->assertSee('value="2/4"', false)->assertSee('value="1/3"', false)->assertSee('3/7');

        $save('SH E-COM', '2/4')->assertOk();
        $this->assertSame(2, \App\Models\OverallTsaCount::count());

        $save('M&J', null)->assertOk()->assertJson(['value' => '']);
        $this->assertSame(1, \App\Models\OverallTsaCount::count());
    }

    public function test_the_number_of_tsas_rejects_bad_values(): void
    {
        $user = $this->admin();
        $save = fn (array $data) => $this->actingAs($user)->patchJson(route('data.overall.sales-summary.tsa-count'), $data + ['date' => '2026-11-01', 'department' => 'SH E-COM']);

        $save(['value' => 'four'])->assertStatus(422);
        $save(['value' => '5/4'])->assertStatus(422);
        $save(['value' => '2/4', 'department' => 'Nobody'])->assertStatus(422);
        $this->assertSame(0, \App\Models\OverallTsaCount::count());
    }

    public function test_a_normal_user_cannot_change_the_number_of_tsas(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']))
            ->patchJson(route('data.overall.sales-summary.tsa-count'), ['date' => '2026-11-01', 'department' => 'SH E-COM', 'value' => '2/4'])
            ->assertForbidden();
    }

    /** M&J's own Leads Report rule: uncalled VIP orders aren't Excess Leads. */
    public function test_only_uncalled_non_restocking_vip_orders_are_excluded_leads(): void
    {
        $order = fn (string $id, array $extra) => \App\Models\Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => 'x', 'product' => 'Sinuxyl', 'raw_tags' => [],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-11-03 10:00:00', 'synced_at' => now(),
        ]);
        $orders = collect([
            $order('vip-uncalled', ['raw_tags' => ['VIP - CRD']]),
            $order('vip-called', ['raw_tags' => ['VIP - CRD', 'CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL']),
            $order('vip-restocking', ['raw_tags' => ['VIP - CRD', 'RESTOCKING ORDERS']]),
            $order('plain-uncalled', []),
        ]);

        $this->assertSame(['vip-uncalled'], \App\Support\ProductPerformance::excludedVipLeads($orders)->pluck('pancake_order_id')->all());
    }
}
