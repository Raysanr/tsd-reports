<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit request (2026-11-17): a newly added TSA shows on every page from
 * the day she was added onwards, never on the days before it.
 */
class NewTsaFirstDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gemmaStartsOn(string $date): TsaShift
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        \Illuminate\Support\Facades\DB::table('tsa_shifts')->where('id', $gemma->id)->update(['first_day' => $date]);

        return $gemma->fresh();
    }

    private function tsaPerformanceKeys(string $from, string $to): array
    {
        return $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $from, 'date_to' => $to]))
            ->assertOk()->viewData('tsaRows')->pluck('tsa_key')->all();
    }

    public function test_adding_a_tsa_sets_her_first_day_to_today(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('tsa-management.store'), ['display_name' => 'Newbie Santos', 'team' => 'SH Naturals'])
            ->assertRedirect();

        $this->assertSame('2026-11-17', TsaShift::where('display_name', 'Newbie Santos')->firstOrFail()->first_day->toDateString());
    }

    public function test_she_shows_from_her_first_day_onwards_on_every_page(): void
    {
        $gemma = $this->gemmaStartsOn('2026-11-17');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Carbon::setTestNow('2026-11-18 18:00:00');
        $card = 'data-tsa-name="' . strtolower($gemma->display_name) . '"';

        $this->get('/data/expected-income?date_from=2026-11-16&date_to=2026-11-16&team=sh-naturals')->assertOk()->assertDontSee($card, false);
        $this->get('/data/expected-income?date_from=2026-11-17&date_to=2026-11-17&team=sh-naturals')->assertOk()->assertSee($card, false);
        $this->get('/data/expected-income?date_from=2026-11-01&date_to=2026-11-30&team=sh-naturals')->assertOk()->assertSee($card, false);

        $this->assertNotContains('Gemma', $this->tsaPerformanceKeys('2026-11-16', '2026-11-16'), 'not before her first day');
        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-11-17', '2026-11-17'), 'her first day');
        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-11-01', '2026-11-30'), 'a range reaching her first day');
    }

    public function test_shared_costs_split_without_her_before_her_first_day(): void
    {
        $count = fn (string $date) => TsaDailyRateService::withDate($date, fn () => TsaDailyRateService::activeTsaCount());
        $before = $count('2026-11-16');
        $this->gemmaStartsOn('2026-11-17');

        $this->assertSame($before - 1, $count('2026-11-16'), 'the other TSAs split the day before she started');
        $this->assertSame($before, $count('2026-11-17'), 'she counts from her first day');
    }
}
