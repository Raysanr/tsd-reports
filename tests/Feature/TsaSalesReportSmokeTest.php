<?php

namespace Tests\Feature;

use App\Models\TsaSalesEntry;
use App\Models\TsaSalesGroup;
use App\Models\TsaSalesRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: "add page summary sales report in data
 * management like this in the sheets." Smoke-level only — confirms the
 * page renders, TSA rows can be added/renamed/removed, and the auto-save
 * endpoint recomputes correctly. Formulas themselves are independently
 * verified in TsaSalesCalculatorTest against the real source sheet.
 */
class TsaSalesReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_report_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();
        $row = TsaSalesRow::create(['tsa_sales_group_id' => $group->id, 'name' => 'Marisol Lagarde', 'sort_order' => 0]);
        TsaSalesEntry::create([
            'tsa_sales_row_id' => $row->id, 'entry_date' => today(),
            'gross_sales' => 5500, 'net_income' => -1379.95, 'ads_spent' => 1426.33,
            'total_orders' => 6, 'catered_leads' => 32, 'pickup_rate' => 0.50, 'upselling_rate' => 0.50,
        ]);

        $response = $this->actingAs($admin)->get(route('data.tsa-sales'));

        $response->assertOk();
        $response->assertSee('MARISOL LAGARDE');
        $response->assertSee('OVERALL TOTAL');
    }

    public function test_the_3_groups_are_seeded_by_default(): void
    {
        $this->assertSame(3, TsaSalesGroup::count());
        $this->assertDatabaseHas('tsa_sales_groups', ['label' => 'Team Opening Shift']);
        $this->assertDatabaseHas('tsa_sales_groups', ['label' => 'Team Closing Shift']);
        $this->assertDatabaseHas('tsa_sales_groups', ['label' => 'Tiktok Upsell']);
    }

    public function test_a_non_admin_cannot_view_the_report_page(): void
    {
        $tsaUser = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($tsaUser)->get(route('data.tsa-sales'));

        $response->assertForbidden();
    }

    public function test_admin_can_add_a_tsa_row_to_a_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();

        $response = $this->actingAs($admin)->postJson(
            route('data.tsa-sales.rows.store', $group),
            ['name' => 'Julie Francisco']
        );

        $response->assertOk();
        $this->assertDatabaseHas('tsa_sales_rows', ['tsa_sales_group_id' => $group->id, 'name' => 'Julie Francisco']);
    }

    public function test_admin_can_rename_a_tsa_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();
        $row = TsaSalesRow::create(['tsa_sales_group_id' => $group->id, 'name' => 'Old Name', 'sort_order' => 0]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.rows.update', $row),
            ['name' => 'New Name']
        );

        $response->assertOk();
        $this->assertDatabaseHas('tsa_sales_rows', ['id' => $row->id, 'name' => 'New Name']);
    }

    public function test_admin_can_remove_a_tsa_row_and_its_entries_cascade(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();
        $row = TsaSalesRow::create(['tsa_sales_group_id' => $group->id, 'name' => 'To Remove', 'sort_order' => 0]);
        $entry = TsaSalesEntry::create(['tsa_sales_row_id' => $row->id, 'entry_date' => today(), 'gross_sales' => 100]);

        $response = $this->actingAs($admin)->deleteJson(route('data.tsa-sales.rows.destroy', $row));

        $response->assertOk();
        $this->assertDatabaseMissing('tsa_sales_rows', ['id' => $row->id]);
        $this->assertDatabaseMissing('tsa_sales_entries', ['id' => $entry->id]);
    }

    public function test_updating_a_cell_upserts_and_returns_recomputed_figures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();
        $row = TsaSalesRow::create(['tsa_sales_group_id' => $group->id, 'name' => 'Marisol Lagarde', 'sort_order' => 0]);

        $response = $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaSalesRow' => $row->id, 'date' => today()->toDateString()]),
            ['gross_sales' => 5500, 'net_income' => -1379.95]
        );

        $response->assertOk();
        $response->assertJsonPath('derived.ni_pct', fn ($v) => abs($v - (-1379.95 / 5500)) < 0.0001);

        $this->assertDatabaseHas('tsa_sales_entries', ['tsa_sales_row_id' => $row->id, 'gross_sales' => 5500]);
    }

    public function test_updating_an_existing_entry_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TsaSalesGroup::first();
        $row = TsaSalesRow::create(['tsa_sales_group_id' => $group->id, 'name' => 'Marisol Lagarde', 'sort_order' => 0]);
        $date = today()->toDateString();

        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaSalesRow' => $row->id, 'date' => $date]),
            ['gross_sales' => 1000]
        );
        $this->actingAs($admin)->patchJson(
            route('data.tsa-sales.update-entry', ['tsaSalesRow' => $row->id, 'date' => $date]),
            ['gross_sales' => 2000]
        );

        $this->assertSame(1, TsaSalesEntry::where('tsa_sales_row_id', $row->id)->whereDate('entry_date', $date)->count());
        $this->assertSame(2000.0, TsaSalesEntry::where('tsa_sales_row_id', $row->id)->whereDate('entry_date', $date)->first()->gross_sales);
    }
}
