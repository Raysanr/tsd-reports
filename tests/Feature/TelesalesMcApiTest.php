<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Support\CostBreakdownMonths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelesalesMcApiTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH = ['Authorization' => 'Bearer mc-token'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telesales_api.token' => 'sales-token', 'services.telesales_api.mc_token' => 'mc-token']);
    }

    public function test_the_sales_token_opens_it_when_no_separate_token_is_set(): void
    {
        CostBreakdownMonths::seed('2026-11');
        config(['services.telesales_api.mc_token' => null]);
        $url = '/api/external/v1/tsd/mc/summary?month=2026-11';

        $this->getJson($url, ['Authorization' => 'Bearer sales-token'])->assertOk();
        $this->getJson($url, ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }

    public function test_a_separate_token_when_set_replaces_the_sales_token(): void
    {
        $url = '/api/external/v1/tsd/mc/summary?month=2026-11';
        $this->getJson($url)->assertStatus(401);
        $this->getJson($url, ['Authorization' => 'Bearer sales-token'])->assertStatus(401);
        $this->getJson('/api/external/v1/telesales/daily?from=2026-11-01&to=2026-11-01', self::AUTH)->assertStatus(401);

        config(['services.telesales_api.mc_token' => null, 'services.telesales_api.token' => null]);
        $this->getJson($url, self::AUTH)->assertStatus(503);
    }

    public function test_it_can_be_limited_to_listed_ips(): void
    {
        CostBreakdownMonths::seed('2026-11');
        config(['services.telesales_api.mc_allowed_ips' => '10.0.0.5, 10.0.0.6']);
        $this->getJson('/api/external/v1/tsd/mc/summary?month=2026-11', self::AUTH)->assertStatus(403);

        config(['services.telesales_api.mc_allowed_ips' => '127.0.0.1']);
        $this->getJson('/api/external/v1/tsd/mc/summary?month=2026-11', self::AUTH)->assertOk();
    }

    public function test_summary_totals_salaries_and_reads_the_pools_without_names(): void
    {
        CostBreakdownMonths::seed('2026-11');
        $tsa = TsaShift::firstOrFail();
        CostBreakdownTsaEntry::updateOrCreate(['month' => '2026-11', 'tsa_id' => $tsa->id], ['base_salary' => 20000, 'days' => 24]);
        CostBreakdownPool::where('month', '2026-11')->where('key', 'sil')->update(['amount' => 1234.5]);

        $roles = CostBreakdownRole::where('month', '2026-11')->get();
        $tsas = CostBreakdownTsaEntry::where('month', '2026-11')->where('base_salary', '>', 0)->get();

        $json = $this->getJson('/api/external/v1/tsd/mc/summary?month=2026-11', self::AUTH)->assertOk()->json();

        $this->assertSame('2026-11', $json['sourceMonth']);
        $this->assertSame($roles->count() + $tsas->count(), $json['headcount']);
        $this->assertEqualsWithDelta($roles->sum('base_salary') + $tsas->sum('base_salary'), $json['salaries'], 0.01);
        $this->assertEquals(1234.5, $json['sil']);
        $this->assertArrayHasKey('hmo', $json['otherLines']);
        $this->assertArrayHasKey('birthdayCake', $json['otherLines']);
        $this->assertStringNotContainsString((string) $tsa->display_name, json_encode($json));
    }

    public function test_roster_lists_roles_and_tsas_and_a_later_month_uses_the_last_saved_one(): void
    {
        CostBreakdownMonths::seed('2026-11');
        $tsa = TsaShift::firstOrFail();
        CostBreakdownTsaEntry::updateOrCreate(['month' => '2026-11', 'tsa_id' => $tsa->id], ['base_salary' => 20000, 'days' => 24]);

        $json = $this->getJson('/api/external/v1/tsd/mc/roster?month=2026-12', self::AUTH)->assertOk()->json();

        $this->assertSame('2026-12', $json['month']);
        $this->assertSame('2026-11', $json['sourceMonth']);
        $agent = collect($json['employees'])->firstWhere('name', $tsa->display_name);
        $this->assertSame('sales team', $agent['group']);
        $this->assertEquals(20000, $agent['monthlySalary']);
        $this->assertSame('leadership', collect($json['employees'])->firstWhere('position', 'CEO')['group']);

        $this->getJson('/api/external/v1/tsd/mc/roster?month=2020-01', self::AUTH)->assertStatus(404);
        $this->getJson('/api/external/v1/tsd/mc/roster?month=bad', self::AUTH)->assertStatus(422);
    }

    public function test_telesales_mc_paths_work_and_shared_executives_can_be_charged_a_share(): void
    {
        CostBreakdownMonths::seed('2026-11');
        $full = $this->getJson('/api/external/v1/telesales/mc/summary?month=2026-11', self::AUTH)->assertOk()->json();
        $this->assertSame($full, $this->getJson('/api/external/v1/tsd/mc/summary?month=2026-11', self::AUTH)->json());

        config(['services.telesales_api.mc_shared_exec_pct' => 33.33]);
        $execs = CostBreakdownRole::where('month', '2026-11')->whereIn('seed_key', ['ceo', 'sales_director'])->sum('base_salary');
        $shared = $this->getJson('/api/external/v1/telesales/mc/summary?month=2026-11', self::AUTH)->json();
        $this->assertEqualsWithDelta($full['salaries'] - $execs * 0.6667, $shared['salaries'], 0.05);

        $roster = $this->getJson('/api/external/v1/telesales/mc/roster?month=2026-11', self::AUTH)->assertOk()->json('employees');
        $this->assertEquals(33.33, collect($roster)->firstWhere('position', 'CEO')['allocationPct']);
        $this->assertEquals(100, collect($roster)->firstWhere('position', 'Telesales Manager')['allocationPct']);
    }
}
