<?php

namespace Tests\Feature;

use App\Models\TsaRestDay;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TsaShiftIsOffOnTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_off_when_no_recurring_day_and_no_override(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();

        $this->assertFalse($julie->isOffOn(Carbon::parse('next sunday')));
    }

    public function test_off_when_date_matches_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $julie->refresh();

        $this->assertTrue($julie->isOffOn(Carbon::parse('next sunday')));
    }

    public function test_not_off_when_date_does_not_match_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $julie->refresh();

        $monday = Carbon::parse('next sunday')->addDay();
        $this->assertFalse($julie->isOffOn($monday));
    }

    public function test_off_on_an_explicit_extra_day_off_with_no_recurring_rule(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $monday = Carbon::parse('next monday');
        TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $monday->toDateString(), 'is_off' => true]);
        $julie->refresh();

        $this->assertTrue($julie->isOffOn($monday));
    }

    public function test_not_off_when_explicit_override_cancels_the_recurring_rest_day(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'sunday']);
        $sunday = Carbon::parse('next sunday');
        TsaRestDay::create(['tsa_shift_id' => $julie->id, 'date' => $sunday->toDateString(), 'is_off' => false]);
        $julie->refresh();

        $this->assertFalse($julie->isOffOn($sunday));
    }

    public function test_rest_day_of_week_is_normalized_to_lowercase_on_write(): void
    {
        $julie = TsaShift::where('tsa_key', 'Julie')->first();
        $julie->update(['rest_day_of_week' => 'Sunday']);
        $julie->refresh();

        $this->assertSame('sunday', $julie->rest_day_of_week);
        $this->assertDatabaseHas('tsa_shifts', ['id' => $julie->id, 'rest_day_of_week' => 'sunday']);
        $this->assertTrue($julie->isOffOn(Carbon::parse('next sunday')));
    }
}
