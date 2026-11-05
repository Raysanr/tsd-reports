<?php

namespace Tests\Feature;

use App\Models\ProjectionCustomRow;
use App\Models\Setting;
use App\Models\User;
use App\Support\ProjectionCalculator;
use App\Support\ProjectionMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Projections rows, rates and row order are per month (2026-10-21). */
class ProjectionRowsPerMonthTest extends TestCase
{
    use RefreshDatabase;

    private function inMonth(string $month, \Closure $fn): mixed
    {
        return ProjectionMonth::with($month, $fn);
    }

    public function test_a_new_month_starts_as_a_copy_and_edits_stay_in_their_month(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // October: add a row and set a rate.
        $this->actingAs($admin)->get(route('data.projections', ['month' => '2026-10']))->assertOk();
        $this->postJson(route('data.projections.custom-rows.store'), ['section' => 'operating', 'label' => 'Office Plants'])->assertOk();
        $this->patchJson(route('data.projections.update-rates'), ['key' => 'rent', 'value' => 0.05])->assertOk();

        // November is created from October.
        $this->get(route('data.projections', ['month' => '2026-11']))->assertOk()->assertSee('Office Plants');
        $this->assertEqualsWithDelta(0.05, $this->inMonth('2026-11', fn () => ProjectionCalculator::rate('rent')), 1e-9);

        // Change November only.
        $this->patchJson(route('data.projections.update-rates'), ['key' => 'rent', 'value' => 0.09])->assertOk();
        $this->postJson(route('data.projections.custom-rows.store'), ['section' => 'selling', 'label' => 'November Only'])->assertOk();
        $plants = $this->inMonth('2026-11', fn () => ProjectionCustomRow::where('key', 'custom_office_plants')->firstOrFail());
        $this->deleteJson(route('data.projections.custom-rows.destroy', $plants->id))->assertOk();

        // October is untouched.
        $this->assertEqualsWithDelta(0.05, $this->inMonth('2026-10', fn () => ProjectionCalculator::rate('rent')), 1e-9);
        $this->get(route('data.projections', ['month' => '2026-10']))->assertOk()
            ->assertSee('Office Plants')->assertDontSee('November Only');

        // November has its own.
        $this->assertEqualsWithDelta(0.09, $this->inMonth('2026-11', fn () => ProjectionCalculator::rate('rent')), 1e-9);
        $this->get(route('data.projections', ['month' => '2026-11']))->assertOk()
            ->assertDontSee('Office Plants')->assertSee('November Only');
        $this->assertNotNull(Setting::get('projection_rate.2026-10.rent'));
    }

    public function test_expected_income_shows_the_rows_of_the_month_being_viewed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('data.projections', ['month' => '2026-10']))->assertOk();
        $this->get(route('data.projections', ['month' => '2026-11']))->assertOk();
        $this->postJson(route('data.projections.custom-rows.store'), ['section' => 'operating', 'label' => 'November Only'])->assertOk();

        $this->get(route('data.expected-income', ['date_from' => '2026-11-03', 'date_to' => '2026-11-03']))->assertOk()->assertSee('November Only');
        $this->get(route('data.expected-income', ['date_from' => '2026-10-03', 'date_to' => '2026-10-03']))->assertOk()->assertDontSee('November Only');
    }

    /** The save response must show the month just edited, not another month's
     *  row with the same key, or the screen jumps back until a reload. */
    public function test_saving_a_column_returns_that_months_figures_when_other_months_exist(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        \App\Models\ProjectionColumn::ensureSeededForMonth('2026-11');
        \App\Models\ProjectionColumn::ensureSeededForMonth('2026-12');
        $november = \App\Models\ProjectionColumn::where('month', '2026-11')->where('key', 'opening_shift')->firstOrFail();
        \App\Models\ProjectionColumn::where('month', '2026-12')->where('key', 'opening_shift')->update(['leads_override' => 8000]);

        $response = $this->patchJson(route('data.projections.update-column', $november), ['leads_override' => 2000])->assertOk();

        $this->assertEqualsWithDelta(2000, $response->json('computed.target_card.leads_needed'), 0.001);
        $opening = collect($response->json('all'))->firstWhere('column.key', 'opening_shift');
        $this->assertSame('2026-11', $opening['column']['month']);
        $this->assertEqualsWithDelta(2000, $opening['target_card']['leads_needed'], 0.001);
    }
}
