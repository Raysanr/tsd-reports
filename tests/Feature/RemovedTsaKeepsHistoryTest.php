<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit request (2026-10-15): "when the tsa remove to the tsa management,
 * ... the day it is deleted the old data is still displaying but onwards it
 * is gone".
 */
class RemovedTsaKeepsHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function removeGemmaOn(string $date): TsaShift
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        Carbon::setTestNow($date . ' 15:00:00');
        $gemma->delete();
        Carbon::setTestNow();

        return $gemma;
    }

    private function gemmaOrderOn(string $date): void
    {
        Order::factory()->create([
            'pancake_order_id' => 'g-' . $date, 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'product' => 'Sinuxyl', 'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'],
            'disposition' => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
    }

    private function tsaPerformanceKeys(string $from, string $to): array
    {
        return $this->get(route('tsa-performance', ['team' => 'all', 'date_from' => $from, 'date_to' => $to]))
            ->assertOk()->viewData('tsaRows')->pluck('tsa_key')->all();
    }

    public function test_her_row_shows_before_and_on_the_removal_day_but_not_after(): void
    {
        $this->removeGemmaOn('2026-10-10');
        $this->gemmaOrderOn('2026-10-09');
        $this->gemmaOrderOn('2026-10-10');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-10-09', '2026-10-09'), 'old data still shows');
        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-10-10', '2026-10-10'), 'the removal day still shows');
        $this->assertNotContains('Gemma', $this->tsaPerformanceKeys('2026-10-11', '2026-10-11'), 'onwards she is gone');
        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-10-01', '2026-10-31'), 'a range covering her old days still shows her');
    }

    /** Explicit request, 2026-11-04: every page shows a removed TSA up to and
     *  including her last working day — Data Management included. */
    public function test_every_page_follows_her_last_working_day(): void
    {
        $gemma = $this->removeGemmaOn('2026-11-02');
        \Illuminate\Support\Facades\DB::table('tsa_shifts')->where('id', $gemma->id)->update(['created_at' => '2026-01-01 00:00:00', 'last_day' => '2026-10-31']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Carbon::setTestNow('2026-11-04 18:00:00');
        $card = 'data-tsa-name="' . strtolower($gemma->display_name) . '"';

        $this->get('/data/expected-income?date_from=2026-10-31&date_to=2026-10-31&team=sh-naturals')->assertOk()->assertSee($card, false);
        $this->get('/data/expected-income?date_from=2026-11-01&date_to=2026-11-01&team=sh-naturals')->assertOk()->assertDontSee($card, false);
        $this->assertContains('Gemma', $this->tsaPerformanceKeys('2026-10-31', '2026-10-31'));
        $this->assertNotContains('Gemma', $this->tsaPerformanceKeys('2026-11-01', '2026-11-01'), 'gone from the day after her last day, though removed Nov 2');
    }

    public function test_removing_asks_for_a_last_working_day_that_can_be_changed_later(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        Carbon::setTestNow('2026-11-04 15:00:00');

        $this->actingAs($admin)->delete(route('tsa-management.destroy', $gemma), ['last_day' => '2026-11-03'])->assertRedirect();
        $this->assertSame('2026-11-03', TsaShift::withTrashed()->find($gemma->id)->last_day->toDateString());

        $this->delete(route('tsa-management.destroy', TsaShift::where('tsa_key', '!=', 'Gemma')->first()), ['last_day' => '2026-11-05'])->assertSessionHasErrors('last_day');

        $this->patch(route('tsa-management.last-day', $gemma->id), ['last_day' => '2026-10-31'])->assertRedirect();
        $this->assertSame('2026-10-31', TsaShift::withTrashed()->find($gemma->id)->last_day->toDateString());
        $this->get(route('tsa-management'))->assertOk()->assertSee('value="2026-10-31"', false);

        $this->post(route('tsa-management.restore', $gemma->id))->assertRedirect();
        $this->assertNull(TsaShift::find($gemma->id)->last_day);
    }

    /** The one-time fill for TSAs removed before last_day existed. */
    public function test_already_removed_tsas_get_the_last_day_the_old_rule_implied(): void
    {
        $gemma = $this->removeGemmaOn('2026-11-02');                       // no November orders → Oct 31
        $other = TsaShift::where('tsa_key', '!=', 'Gemma')->firstOrFail();
        Order::factory()->create(['pancake_order_id' => 'o-1', 'team' => $other->team, 'tsa_name' => $other->tsa_key, 'product' => 'Sinuxyl',
            'raw_tags' => [], 'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => '2026-11-01 10:00:00', 'synced_at' => now()]);
        Carbon::setTestNow('2026-11-04 15:00:00');
        $other->delete();                                                  // sold Nov 1 → Nov 4
        Carbon::setTestNow();
        \Illuminate\Support\Facades\DB::table('tsa_shifts')->update(['last_day' => null]);

        (require database_path('migrations/2026_11_04_000001_add_last_day_to_tsa_shifts_table.php'))->up();

        $this->assertSame('2026-10-31', TsaShift::withTrashed()->find($gemma->id)->last_day->toDateString());
        $this->assertSame('2026-11-04', TsaShift::withTrashed()->find($other->id)->last_day->toDateString());
    }

    public function test_live_pages_and_tsa_management_still_treat_her_as_removed(): void
    {
        $gemma = $this->removeGemmaOn('2026-10-10');

        $this->assertNull(TsaShift::find($gemma->id), 'no report date set → plain soft delete');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertFalse($this->get(route('calls.monitor'))->assertOk()->viewData('tsas')->contains('id', $gemma->id));
    }

    public function test_the_report_date_never_leaks_into_the_next_request(): void
    {
        $gemma = $this->removeGemmaOn('2026-10-10');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->tsaPerformanceKeys('2026-10-09', '2026-10-09');
        $this->app->instance('request', \Illuminate\Http\Request::create('/next'));

        $this->assertNull(TsaShift::rosterFrom());
        $this->assertNull(TsaShift::find($gemma->id));
    }

    private function restoreAndRemoveGemma(array $removal = []): TsaShift
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Carbon::setTestNow('2026-09-29 20:00:00');
        $this->delete(route('tsa-management.destroy', $gemma), ['last_day' => '2026-09-29']);
        Carbon::setTestNow('2026-11-02 21:00:00');
        $this->post(route('tsa-management.restore', $gemma->id));
        Carbon::setTestNow('2026-11-02 21:45:00');
        $this->delete(route('tsa-management.destroy', $gemma), $removal + ['last_day' => '2026-11-02']);

        return TsaShift::withTrashed()->find($gemma->id);
    }

    /** Hannah, 2026-11-02: restored and removed again without working. */
    public function test_restoring_and_removing_keeps_her_earlier_last_day(): void
    {
        $this->assertSame('2026-09-29', $this->restoreAndRemoveGemma()->last_day->toDateString());
    }

    public function test_a_date_picked_on_purpose_is_kept(): void
    {
        $this->assertSame('2026-10-15', $this->restoreAndRemoveGemma(['last_day' => '2026-10-15'])->last_day->toDateString());
    }

    public function test_she_gets_the_new_date_if_she_really_came_back(): void
    {
        \App\Models\Order::create(['pancake_order_id' => 'back', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma', 'status_code' => 1, 'pancake_created_at' => '2026-10-20 10:00:00', 'synced_at' => now()]);

        $this->assertSame('2026-11-02', $this->restoreAndRemoveGemma()->last_day->toDateString());
    }
}
