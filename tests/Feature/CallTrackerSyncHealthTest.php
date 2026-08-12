<?php

namespace Tests\Feature;

use App\Models\LeadSyncRun;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) as
 * CallTrackerSyncHealthTest — renamed from call-tracker's own SyncHealthTest
 * because tsd-reports already has an unrelated tests/Feature/SyncHealthTest.php
 * for its OWN SyncHealthController (Pancake order sync health, not Call
 * Tracker's lead sync). Tsa -> TsaShift, SyncRun -> LeadSyncRun, routes -> calls.*.
 */
class CallTrackerSyncHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
    }

    public function test_a_tsa_cannot_reach_sync_health(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.sync-health'))->assertForbidden();
    }

    public function test_a_successful_sync_records_a_sync_run(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => [[
                'id' => 5001, 'bill_full_name' => 'Sync Health Check', 'tags' => [],
                'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
                'inserted_at' => now()->toIso8601String(),
            ]]], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*/tags' => Http::response(['success' => true], 200),
        ]);

        Artisan::call('pancake:sync-leads');

        $this->assertSame(1, LeadSyncRun::count());
        $run = LeadSyncRun::first();
        $this->assertTrue($run->success);
        $this->assertSame(1, $run->new_leads);
        $this->assertNull($run->error_message);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('calls.sync-health'))->assertOk();
    }

    public function test_a_failed_sync_records_a_failed_run_with_the_error_message(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response('Server error', 500),
        ]);

        Artisan::call('pancake:sync-leads');

        $run = LeadSyncRun::first();
        $this->assertFalse($run->success);
        $this->assertNotNull($run->error_message);
    }

    public function test_missing_api_key_records_a_failed_run_without_calling_pancake(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');
        Http::fake();

        Artisan::call('pancake:sync-leads');

        Http::assertNothingSent();
        $run = LeadSyncRun::first();
        $this->assertNotNull($run);
        $this->assertFalse($run->success);
    }
}
