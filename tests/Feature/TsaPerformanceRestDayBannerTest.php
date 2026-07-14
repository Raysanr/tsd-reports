<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaPerformanceRestDayBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_day_off_banner_when_viewing_a_rest_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday')->toDateString();

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $sunday,
            'date_to'   => $sunday,
        ]));

        $response->assertOk();
        $response->assertSee('is marked as off on');
    }

    public function test_does_not_show_day_off_banner_on_a_working_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $monday = Carbon::parse('next sunday')->addDay()->toDateString();

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $monday,
            'date_to'   => $monday,
        ]));

        $response->assertOk();
        $response->assertDontSee('is marked as off on');
    }

    public function test_does_not_show_banner_for_a_multi_day_range_even_if_it_includes_a_rest_day(): void
    {
        $this->actingAs(User::factory()->create());

        $julie  = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');

        $response = $this->get(route('tsa-performance.individual', [
            'team'      => 'eyecare',
            'tsaKey'    => 'Julie',
            'date_from' => $sunday->toDateString(),
            'date_to'   => $sunday->copy()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('is marked as off on');
    }
}
