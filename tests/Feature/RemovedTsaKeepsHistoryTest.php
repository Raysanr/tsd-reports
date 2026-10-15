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
}
