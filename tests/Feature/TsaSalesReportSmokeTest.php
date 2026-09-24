<?php

namespace Tests\Feature;

use App\Models\TsaSalesEntry;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: "add page summary sales report in data
 * management like this in the sheets," then "i want to make it like
 * auto based on the current team and tsa" — TSA rows are the app's own
 * real TsaShift records, grouped by team, no admin row management left.
 * Smoke-level only — formulas themselves are independently verified in
 * TsaSalesCalculatorTest against the real source sheet.
 */
class TsaSalesReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        TsaSalesEntry::create([
            'tsa_shift_id' => $tsa->id, 'entry_date' => today(),
            'gross_sales' => 5500, 'net_income' => -1379.95, 'ads_spent' => 1426.33,
            'total_orders' => 6, 'catered_leads' => 32, 'pickup_rate' => 0.50, 'upselling_rate' => 0.50,
        ]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee(strtoupper($tsa->display_name));
        $response->assertSee('OVERALL TOTAL');
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.tsa-sales'));

        $response->assertForbidden();
    }

    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => today()->toDateString()]),
            ['gross_sales' => 5500, 'net_income' => -1379.95]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (-1379.95 / 5500)) < 0.0001);

        $this->assertDatabaseHas('tsa_sales_entries', ['tsa_shift_id' => $tsa->id, 'gross_sales' => 5500]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tsa = TsaShift::first();
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaShift' => $tsa->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, TsaSalesEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, TsaSalesEntry::where('tsa_shift_id', $tsa->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }

    public function test_tsas_are_grouped_by_their_real_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee('SH Naturals');
        $response->assertSee('Eyecare');
    }
}
