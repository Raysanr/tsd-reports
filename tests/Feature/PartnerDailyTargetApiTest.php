<?php

namespace Tests\Feature;

use App\Models\ProjectionColumn;
use App\Support\DataSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/partner/v1/daily-target (2026-11-27) — partner apps' Daily Target
 *  bubble reads its target from Partners Data Management → Projections. */
class PartnerDailyTargetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['partners.secret' => 'shared-secret']);
        $this->artisan('migrate', ['--database' => DataSet::PARTNERS]);
    }

    protected function tearDown(): void
    {
        DataSet::reset();
        parent::tearDown();
    }

    private function fetchTarget(array $query, ?string $token = 'shared-secret')
    {
        return $this->withToken((string) $token)->getJson(route('api.partner.daily-target', $query));
    }

    public function test_returns_the_partners_individual_tsa_daily_gross_sales(): void
    {
        DataSet::onPartners(function () {
            ProjectionColumn::ensureSeededForMonth('2026-11');
            // 24 orders × ₱1,000 = ₱24,000 monthly for 1 TSA ÷ 24 working days = ₱1,000/day.
            ProjectionColumn::where('month', '2026-11')->where('key', 'mk_shift')
                ->update(['orders_override' => 24, 'average_order_value' => 1000, 'tsa_count' => 1]);
        });

        $this->fetchTarget(['partner' => 'mk', 'month' => '2026-11'])->assertOk()->assertExactJson([
            'partner' => 'mk', 'name' => 'M&K', 'month' => '2026-11', 'daily_target' => 1000,
        ]);
        // Another partner's column is untouched — no target yet.
        $this->fetchTarget(['partner' => 'mj', 'month' => '2026-11'])->assertOk()->assertJsonPath('daily_target', null);
    }

    public function test_null_when_the_month_has_no_projections(): void
    {
        $this->fetchTarget(['partner' => 'mk', 'month' => '2030-01'])->assertOk()->assertJsonPath('daily_target', null);
    }

    public function test_rejects_a_wrong_secret_and_an_unknown_partner(): void
    {
        $this->fetchTarget(['partner' => 'mk'], 'nope')->assertUnauthorized();
        $this->fetchTarget(['partner' => 'nobody'])->assertUnprocessable();
    }
}
