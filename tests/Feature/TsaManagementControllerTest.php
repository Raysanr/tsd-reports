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
}
