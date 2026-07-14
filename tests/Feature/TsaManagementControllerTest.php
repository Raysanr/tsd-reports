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
}
