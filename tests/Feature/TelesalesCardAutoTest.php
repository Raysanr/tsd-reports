<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\TsaSalesReportController;
use App\Models\TelesalesSummary;
use App\Models\User;
use App\Support\DataSet;
use App\Support\TelesalesCardAuto;
use App\Support\Teams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Telesales Department card filled from Sales Summary (2026-11-16). */
class TelesalesCardAutoTest extends TestCase
{
    use RefreshDatabase;

    private string $date;

    private array $orderTeams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->toDateString();
        $this->orderTeams = collect(Teams::config())->pluck('order_team')->values()->all();
        [$a, $b] = $this->orderTeams + [1 => 'Other'];
        $d = $this->date;
        $calls = ['answered' => 0, 'unanswered' => 0, 'confirmed_via_call' => 0, 'upsell_confirmation' => 0];

        $tsd = [
            'days'  => [$d => ['gross_sales' => 6000, 'net_income' => -11585.03, 'total_orders' => 5, 'call_counts' => $calls]],
            'teams' => [
                $a => [$d => ['gross_sales' => 4000, 'net_income' => -5000]],
                $b => [$d => ['gross_sales' => 2000, 'net_income' => -6585.03]],
            ],
            'tsas'  => [$d => [
                ['name' => 'Jhun', 'team' => $a, 'gross_sales' => 4000, 'net_income' => 900, 'catered_leads' => 40],
                ['name' => 'Mariel', 'team' => $b, 'gross_sales' => 2000, 'net_income' => 300, 'catered_leads' => 30],
                ['name' => 'Idle', 'team' => $b, 'gross_sales' => 0, 'net_income' => 0, 'catered_leads' => 0],
            ]],
        ];
        $partners = [
            'teams' => [
                'M&J' => [$d => ['gross_sales' => 8000, 'income_before_opex' => 6600.27, 'shared_income' => 4620.19, 'net_income' => -5376.15, 'total_orders' => 3, 'total_leads' => 10, 'catered_leads' => 8, 'excess_leads' => 0, 'call_counts' => $calls]],
                'MLC' => [$d => ['gross_sales' => 9000, 'income_before_opex' => 12318.49, 'shared_income' => 8622.95, 'net_income' => 1898.89, 'total_orders' => 3, 'total_leads' => 10, 'catered_leads' => 8, 'excess_leads' => 0, 'call_counts' => $calls]],
            ],
            'tsas'  => [$d => [['name' => 'MLC Star', 'team' => 'MLC', 'gross_sales' => 3000, 'net_income' => 1200, 'catered_leads' => 20]]],
        ];

        $this->mock(TsaSalesReportController::class)->shouldReceive('overallByDate')
            ->andReturnUsing(fn () => DataSet::isPartners() ? $partners : $tsd);
    }

    public function test_it_fills_the_card_from_sales_summary(): void
    {
        $auto = TelesalesCardAuto::forDates([$this->date])[$this->date];

        $this->assertEquals(6000, $auto->gross_sales);                 // SH ECOMMERCE sales
        $this->assertEquals(-11585.03 - 5376.15 + 1898.89, $auto->net_income); // OVERALL TOTAL net income
        $this->assertSame('MLC Star', $auto->top_seller_name);          // highest net income, partners included
        $this->assertEquals(1200, $auto->top_seller_net_income);
        $this->assertSame('PARTNERS TEAM', $auto->top_team_name); // -3,477.26 vs SH E-COM -11,585.03
        $this->assertEqualsWithDelta(-5376.15 + 1898.89, $auto->top_team_net_income, 0.01);
        $this->assertEquals(17000, $auto->top_team_gross_sales);
        // TSAs with gross sales: Jhun + Mariel (Idle has none), and MLC Star.
        $this->assertSame(['SH ECOM' => 2, 'PARTNERS' => 1], array_column($auto->sub_team_counts, 'count', 'name'));
        $this->assertSame(3, $auto->overall_working_tsas);
        $this->assertFalse($auto->exists);
    }

    public function test_the_card_is_automatic_and_read_only(): void
    {
        $this->actingAs(User::factory()->create());
        // An old typed entry no longer overrides Sales Summary.
        TelesalesSummary::create(['summary_date' => $this->date, 'gross_sales' => 123, 'net_income' => 45, 'sub_team_counts' => [], 'overall_working_tsas' => 0]);

        $this->getJson(route('telesales-summary.show', ['date' => $this->date]))
            ->assertJsonPath('summary.top_seller_name', 'MLC Star')
            ->assertJsonPath('summary.gross_sales', '6000.00');

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('value="6000', false)->assertSee('MLC Star')
            ->assertSee('readonly tabindex="-1"', false)
            ->assertDontSee('data-tss-today-save', false)->assertDontSee('data-tss-small-save', false);
    }

    public function test_the_card_shows_sh_ecom_and_partners_tsa_counts_read_only(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('SH ECOM')->assertSee('PARTNERS')
            ->assertSee('data-subteam-count value="2" placeholder="0" class="tsa-in" readonly', false)
            ->assertSee('data-subteam-count value="1" placeholder="0" class="tsa-in" readonly', false)
            ->assertDontSee('Automatic from Sales Summary');
    }
}
