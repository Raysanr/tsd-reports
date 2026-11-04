<?php

namespace Tests\Feature;

use App\Models\ProjectionColumn;
use App\Models\User;
use App\Support\DataSet;
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
            ->assertOk()->assertSee('SH E-COM')->assertSee('PARTNERS')->assertSee('Tue, Nov 3');

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
}
