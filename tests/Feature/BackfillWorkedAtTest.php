<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * pancake:backfill-worked-at mirrors pancake:backfill-inserted-at's exact
 * mechanism (re-sync each day in a range; pancake:sync-today upserts on
 * pancake_order_id and recomputes pancake_created_at fresh every run using
 * the fixed disposition-tag-first logic) — these tests just prove the date
 * range/loop wiring, not the disposition-tag fix itself (already covered by
 * SyncTodayOrdersDispositionAnchorTest).
 */
class BackfillWorkedAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::response(['data' => []]),
        ]);
    }

    public function test_re_syncs_every_day_in_an_explicit_from_to_range(): void
    {
        $exitCode = Artisan::call('pancake:backfill-worked-at', [
            '--from' => '2026-07-01',
            '--to'   => '2026-07-03',
        ]);

        $this->assertSame(0, $exitCode);
        // One SyncRun row per day re-synced (07-01, 07-02, 07-03) — same
        // assertion style as DashboardSyncFeedbackTest's own range test.
        $this->assertSame(3, SyncRun::count());
    }

    public function test_defaults_to_days_back_from_to_when_from_is_omitted(): void
    {
        $exitCode = Artisan::call('pancake:backfill-worked-at', [
            '--to'   => '2026-07-10',
            '--days' => 5,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(5, SyncRun::count());
    }

    public function test_rejects_a_from_date_after_the_to_date(): void
    {
        $exitCode = Artisan::call('pancake:backfill-worked-at', [
            '--from' => '2026-07-10',
            '--to'   => '2026-07-01',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, SyncRun::count());
    }
}
