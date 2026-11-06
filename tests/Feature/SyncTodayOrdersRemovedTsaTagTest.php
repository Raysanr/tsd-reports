<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * loadTsaMaps() uses the roster as it stood on the synced date (2026-11-02:
 * removing six TSAs made the next night's 3-day reconcile wipe their earlier
 * orders' tsa_name, dumping them into Unassigned on TSA Performance).
 */
class SyncTodayOrdersRemovedTsaTagTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function syncGemmaTaggedOrderOn(string $date): ?Order
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [[
                    'id'          => 9101,
                    'status'      => 2,
                    'total_price' => 1000,
                    'inserted_at' => $date . 'T03:00:00',
                    'updated_at'  => $date . 'T03:00:00',
                    'tags'        => [['id' => 1, 'name' => 'GEMMA'], ['id' => 2, 'name' => 'CONFIRMED VIA CALL']],
                    'items'       => [['variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 1000], 'quantity' => 1]],
                ]]])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => $date]);

        return Order::where('pancake_order_id', '9101')->first();
    }

    private function removeGemmaOn(string $date): void
    {
        Carbon::setTestNow($date . ' 19:42:00');
        TsaShift::where('tsa_key', 'Gemma')->firstOrFail()->delete();
        Carbon::setTestNow();
    }

    public function test_a_tsa_removed_after_the_synced_date_keeps_her_tagged_orders(): void
    {
        $this->removeGemmaOn('2026-11-01');

        $this->assertSame('Gemma', $this->syncGemmaTaggedOrderOn('2026-10-31')?->tsa_name);
    }

    public function test_a_tsa_removed_on_the_synced_date_still_counts_that_day(): void
    {
        $this->removeGemmaOn('2026-10-31');

        $this->assertSame('Gemma', $this->syncGemmaTaggedOrderOn('2026-10-31')?->tsa_name);
    }

    public function test_a_tsa_removed_before_the_synced_date_no_longer_claims_her_tag(): void
    {
        $this->removeGemmaOn('2026-10-30');

        $this->assertNull($this->syncGemmaTaggedOrderOn('2026-10-31')?->tsa_name);
    }

    private function fakeOrderUpdatedToday(array $tags): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [[
                    'id'          => 9101,
                    'status'      => 2,
                    'total_price' => 1000,
                    'inserted_at' => '2026-10-15T03:00:00',
                    'updated_at'  => '2026-11-06T03:00:00',
                    'tags'        => $tags,
                    'items'       => [['variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 1000], 'quantity' => 1]],
                ]]])
                ->push(['data' => []]),
        ]);
    }

    /** Today's sync also fetches old orders touched today (2026-11-06). */
    public function test_an_old_order_updated_today_keeps_a_tsa_removed_since(): void
    {
        $this->removeGemmaOn('2026-11-03');
        Carbon::setTestNow('2026-11-06 12:00:00');
        $this->fakeOrderUpdatedToday([['id' => 1, 'name' => 'GEMMA'], ['id' => 2, 'name' => 'CONFIRMED VIA CALL']]);

        Artisan::call('pancake:sync-today');

        $this->assertSame('Gemma', Order::where('pancake_order_id', '9101')->value('tsa_name'));
    }

    public function test_a_sync_that_finds_no_tsa_never_erases_a_saved_name(): void
    {
        Order::create(['pancake_order_id' => '9101', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma', 'status_code' => 2, 'pancake_created_at' => '2026-10-15 11:00:00', 'synced_at' => now()]);
        Carbon::setTestNow('2026-11-06 12:00:00');
        $this->fakeOrderUpdatedToday([['id' => 2, 'name' => 'Unreconciled']]);

        Artisan::call('pancake:sync-today');

        $this->assertSame('Gemma', Order::where('pancake_order_id', '9101')->value('tsa_name'));
    }
}
