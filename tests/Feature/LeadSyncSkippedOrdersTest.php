<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSyncSkip;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Explicit report (2026-10-15): Pancake orders with no assignee (e.g.
 *  #1381321) never reached Call Tracker — Sync Health now lists every
 *  order today that didn't become a working lead, and why. */
class LeadSyncSkippedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $id, string $item, string $phone, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'bill_full_name' => "Customer {$id}", 'bill_phone_number' => $phone, 'tags' => [],
            'items' => [['variation_info' => ['name' => $item]]],
            'inserted_at' => now('UTC')->format('Y-m-d\TH:i:s'),
        ], $extra);
    }

    public function test_sync_health_lists_todays_orders_that_did_not_become_leads_with_the_reason(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => [
                $this->order(7001, 'Sinuxyl', '09300475041'),
                $this->order(7002, 'Sinuxyl', '09300475041'),            // same phone + product, same day
                $this->order(7003, 'Mystery Item', '09170000000'),       // not in Product Management
                $this->order(7004, 'Sinuxyl', '09180000000', ['note' => 'DUPLICATED BY LOGISTICS']),
            ]], 200),
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['success' => true], 200),
        ]);

        Artisan::call('pancake:sync-leads');

        $this->assertNotNull(Lead::where('pancake_order_id', '7001')->first());
        $this->assertSame('duplicate', LeadSyncSkip::where('pancake_order_id', '7002')->value('reason'));
        $this->assertStringContainsString('Duplicate of #7001', LeadSyncSkip::where('pancake_order_id', '7002')->value('detail'));
        $this->assertSame('logistics_note', LeadSyncSkip::where('pancake_order_id', '7004')->value('reason'));

        $rows = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('calls.sync-health'))->assertOk()->viewData('notDistributed');

        $byOrder = $rows->keyBy('order');
        $this->assertStringContainsString('Duplicate order', $byOrder['7002']['reason']);
        $this->assertStringContainsString('Product Management', $byOrder['7003']['reason']);
        $this->assertStringContainsString('Duplicated by logistics', $byOrder['7004']['reason']);
        // No TSA logged in during the test → the real lead waits, and says so.
        $this->assertStringContainsString('no TSA online', $byOrder['7001']['reason']);
    }
}
