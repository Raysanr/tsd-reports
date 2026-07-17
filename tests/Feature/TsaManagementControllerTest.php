<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_index_passes_a_calendar_for_the_requested_month(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);

        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-management', ['month' => $sunday->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('calendar');

        $calendar = $response->viewData('calendar');
        $this->assertSame($sunday->format('F Y'), $calendar['month_label']);
        $this->assertCount($sunday->daysInMonth, $calendar['days']);

        $sundayEntry = collect($calendar['days'])->firstWhere('date', $sunday->toDateString());
        $this->assertNotNull($sundayEntry);
        $this->assertTrue($sundayEntry['off_tsas']->contains('tsa_key', 'Julie'));
    }

    public function test_index_defaults_to_the_current_month_with_no_month_param(): void
    {
        $response = $this->get(route('tsa-management'));

        $response->assertOk();
        $calendar = $response->viewData('calendar');
        $this->assertSame(now('Asia/Manila')->format('F Y'), $calendar['month_label']);
    }

    public function test_index_falls_back_to_the_current_month_for_an_invalid_month_param(): void
    {
        $response = $this->get(route('tsa-management', ['month' => 'not-a-month']));

        $response->assertOk();
        $calendar = $response->viewData('calendar');
        $this->assertSame(now('Asia/Manila')->format('F Y'), $calendar['month_label']);
    }

    public function test_calendar_cell_lists_the_tsa_key_off_on_that_date(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-management', ['month' => $sunday->format('Y-m')]));

        $response->assertOk();
        $response->assertSee('data-date="' . $sunday->toDateString() . '" data-off="Julie"', false);
    }

    public function test_calendar_cell_has_no_off_tsas_on_a_normal_working_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $monday = Carbon::parse('next sunday')->addDay();

        $response = $this->get(route('tsa-management', ['month' => $monday->format('Y-m')]));

        $response->assertOk();
        $response->assertSee('data-date="' . $monday->toDateString() . '" data-off=""', false);
    }

    public function test_calendar_month_navigation_links_are_present(): void
    {
        $month = Carbon::parse('next sunday')->format('Y-m');

        $response = $this->get(route('tsa-management', ['month' => $month]));

        $response->assertOk();
        $response->assertSee('month=' . Carbon::createFromFormat('Y-m', $month)->subMonthNoOverflow()->format('Y-m'), false);
        $response->assertSee('month=' . Carbon::createFromFormat('Y-m', $month)->addMonthNoOverflow()->format('Y-m'), false);
    }

    public function test_save_rest_days_creates_an_override_for_a_non_recurring_extra_day_off(): void
    {
        $marisol = TsaShift::where('tsa_key', 'Marisol')->first();
        $monday  = Carbon::parse('next monday');

        $response = $this->post(route('tsa-management.rest-days', $monday->toDateString()), [
            'tsas' => ['Marisol'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tsa_rest_days', [
            'tsa_shift_id' => $marisol->id,
            'date'         => $monday->toDateString(),
            'is_off'       => true,
        ]);
    }

    public function test_save_rest_days_does_not_create_a_row_when_it_matches_the_recurring_default(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => ['Julie'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('tsa_rest_days', ['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString()]);
    }

    public function test_save_rest_days_creates_an_override_when_unchecking_a_recurring_rest_day(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => [], // Julie unchecked despite her recurring Sunday off
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tsa_rest_days', [
            'tsa_shift_id' => $julie->id,
            'date'         => $sunday->toDateString(),
            'is_off'       => false,
        ]);
    }

    public function test_save_rest_days_deletes_a_stale_override_that_now_matches_the_recurring_default(): void
    {
        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');
        \App\Models\TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString(), 'is_off' => false]);

        $response = $this->post(route('tsa-management.rest-days', $sunday->toDateString()), [
            'tsas' => ['Julie'], // checked again, matches the recurring default now
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('tsa_rest_days', ['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString()]);
    }

    public function test_store_persists_rest_day_of_week(): void
    {
        $response = $this->post(route('tsa-management.store'), [
            'display_name'     => 'New TSA',
            'team'              => 'SH Naturals',
            'rest_day_of_week'  => 'monday',
        ]);

        $response->assertRedirect(route('tsa-management'));
        $this->assertDatabaseHas('tsa_shifts', ['display_name' => 'New TSA', 'rest_day_of_week' => 'monday']);
    }

    public function test_update_can_clear_rest_day_of_week(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);

        $response = $this->put(route('tsa-management.update', $julie), [
            'display_name' => $julie->display_name,
            'team'          => $julie->team,
            // rest_day_of_week omitted entirely -> should clear back to null
        ]);

        $response->assertRedirect(route('tsa-management'));
        $this->assertDatabaseHas('tsa_shifts', ['id' => $julie->id, 'rest_day_of_week' => null]);
    }

    public function test_store_rejects_an_invalid_rest_day_value(): void
    {
        $response = $this->post(route('tsa-management.store'), [
            'display_name'     => 'Bad TSA',
            'team'              => 'SH Naturals',
            'rest_day_of_week'  => 'someday',
        ]);

        $response->assertSessionHasErrors('rest_day_of_week');
        $this->assertDatabaseMissing('tsa_shifts', ['display_name' => 'Bad TSA']);
    }

    public function test_bulk_move_changes_team_for_multiple_shifts(): void
    {
        $ids = TsaShift::whereIn('tsa_key', ['Julie', 'Marisol'])->pluck('id');

        $response = $this->post(route('tsa-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'move',
            'team'   => 'Eyecare Team',
        ]);

        $response->assertRedirect(route('tsa-management'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('tsa_shifts', ['id' => $id, 'team' => 'Eyecare Team']);
        }
    }

    public function test_bulk_delete_soft_deletes_multiple_shifts(): void
    {
        $ids = TsaShift::whereIn('tsa_key', ['Julie', 'Marisol'])->pluck('id');

        $response = $this->post(route('tsa-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'delete',
        ]);

        $response->assertRedirect(route('tsa-management'));
        foreach ($ids as $id) {
            $this->assertSoftDeleted('tsa_shifts', ['id' => $id]);
        }
    }

    public function test_bulk_move_without_a_team_fails_validation(): void
    {
        $ids = TsaShift::whereIn('tsa_key', ['Julie', 'Marisol'])->pluck('id');

        $response = $this->post(route('tsa-management.bulk'), [
            'ids'    => $ids->all(),
            'action' => 'move',
        ]);

        $response->assertSessionHasErrors('team');
    }
}
