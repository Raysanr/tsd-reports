<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One-off backfill (2026-09-18) for the addUpsellItem() silent-tag-loss
 * incident — see BackfillLostUpsellTags' own doc comment for the full
 * root cause. These tests fake the tag re-add's HTTP calls directly
 * (addTagsToOrder()'s own GET-PUT-verify shape) and fake pancake:sync-
 * today itself (Artisan::call) rather than re-deriving its whole real
 * sync logic here — this command's own job is only to re-add the tag
 * and hand off to that command for the recompute, so these tests assert
 * exactly that handoff, not SyncTodayOrders' internals a second time.
 */
class BackfillLostUpsellTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '4');
    }

    private function makeLostUpsell(string $orderId, string $productName, string $tsaKey = 'Gemma'): Lead
    {
        $tsa     = TsaShift::where('tsa_key', $tsaKey)->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        $lead = Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Test Customer',
            'product_id' => $product->id, 'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
        Order::factory()->create([
            'pancake_order_id' => $orderId, 'is_upsell' => false, 'is_returned_upsell' => false,
            'is_upsell_on_voided_order' => false, 'raw_tags' => [], 'pancake_created_at' => now(),
        ]);
        LeadActivity::log($lead, 'upsell_added', "Added upsell \"{$productName}\" (₱499.00 × 1) by Test TSA.", null, 499.0);

        return $lead;
    }

    public function test_re_adds_the_missing_tag_and_resyncs_the_affected_date(): void
    {
        $this->makeLostUpsell('9001', 'Haplunas Balm');

        // pancake:sync-today's own real HTTP calls (page-walk GET +
        // per-order detail fetch) are faked too — this test asserts the
        // handoff actually happens (the tag lands, and the resync command
        // runs without erroring), not that SyncTodayOrders' own recompute
        // logic is correct a second time (that's its own test suite's job).
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 30, 'name' => 'UPSELL TSD - Haplunas Balm'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::sequence()
                ->push(['success' => true, 'data' => ['id' => 9001, 'tags' => []]], 200)
                ->push(['success' => true], 200)
                ->push(['success' => true, 'data' => [
                    'id' => 9001, 'tags' => [['id' => 30, 'name' => 'UPSELL TSD - Haplunas Balm']],
                ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('calls:backfill-lost-upsell-tags')->assertSuccessful();

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            return collect($r['tags'])->pluck('id')->contains(30);
        });
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $this->makeLostUpsell('9002', 'Ear Relief Balm');

        Http::fake();

        $this->artisan('calls:backfill-lost-upsell-tags', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_an_order_already_correctly_counted(): void
    {
        $this->makeLostUpsell('9003', 'Eye Drops');
        Order::where('pancake_order_id', '9003')->update(['is_upsell' => true]);

        Http::fake();

        $this->artisan('calls:backfill-lost-upsell-tags')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_failed_tag_add_report_that_still_fails_verification_is_not_silently_counted_as_fixed(): void
    {
        $this->makeLostUpsell('9004', 'Rose Body Oil');

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 40, 'name' => 'UPSELL TSD - Rose Body Oil'],
            ]], 200),
            // Tag genuinely never lands even on this retry — verify GET
            // keeps showing an empty tags array.
            'pos.pages.fm/api/v1/shops/4/orders/9004*' => Http::response(['success' => true, 'data' => [
                'id' => 9004, 'tags' => [],
            ]], 200),
        ]);

        $this->artisan('calls:backfill-lost-upsell-tags')->assertSuccessful();

        // No resync call for a date whose only candidate order failed.
        $this->assertFalse(Order::where('pancake_order_id', '9004')->first()->is_upsell);
    }

    public function test_a_lead_activity_with_no_matching_order_is_skipped_without_error(): void
    {
        $tsa = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9999', 'customer_name' => 'No Order', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
        LeadActivity::log($lead, 'upsell_added', 'Added upsell "Ghost Product" (₱100.00 × 1) by Test TSA.', null, 100.0);

        Http::fake();

        $this->artisan('calls:backfill-lost-upsell-tags')->assertSuccessful();

        Http::assertNothingSent();
    }
}
