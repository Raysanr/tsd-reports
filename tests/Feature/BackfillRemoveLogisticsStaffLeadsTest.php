<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One-off cleanup (2026-09-28) for leads already synced BEFORE
 * Order::isCreatedByLogisticsStaff() existed — real production orders
 * #1373293/#1373292/#1373288/#1373287, all created by AJ Dela Cruz, were
 * already synced as Leads and sitting in TSAs' queues. SyncPancakeLeads now
 * stops any NEW order created by logistics staff from ever becoming a Lead,
 * but does nothing for ones already created before it existed.
 */
class BackfillRemoveLogisticsStaffLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '4');
    }

    public function test_removes_an_untouched_leads_todays_lead_created_by_logistics_staff(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id'   => '1373292',
            'customer_name'      => 'Janet Ignacio Bonifacio',
            'product_id'         => $product->id,
            'status'             => 'assigned',
            'pancake_created_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1373292*' => Http::response(['data' => [
                'id' => 1373292, 'creator' => ['id' => 'staff-1', 'name' => 'AJ Dela Cruz'],
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-remove-logistics-leads');

        $this->assertDatabaseMissing('leads', ['pancake_order_id' => '1373292']);
    }

    public function test_leaves_an_ordinary_leads_todays_lead_untouched(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id'   => '9101',
            'customer_name'      => 'Real Customer',
            'product_id'         => $product->id,
            'status'             => 'assigned',
            'pancake_created_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9101*' => Http::response(['data' => [
                'id' => 9101, 'creator' => ['id' => 'staff-2', 'name' => 'Some Marketer'],
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-remove-logistics-leads');

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '9101']);
    }

    /** Explicit request, 2026-09-28: a lead a TSA already called or logged a
     *  disposition on is left alone even if its order matches — a real
     *  human already did work on it, so it's not silently removed. */
    public function test_leaves_an_already_called_lead_untouched_even_if_created_by_logistics_staff(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id'   => '1373293',
            'customer_name'      => 'Some Customer',
            'product_id'         => $product->id,
            'status'             => 'called',
            'called_at'          => now(),
            'disposition'        => 'callback',
            'pancake_created_at' => now(),
        ]);

        Http::fake();

        Artisan::call('pancake:backfill-remove-logistics-leads');

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '1373293']);
        Http::assertNothingSent();
    }

    public function test_leaves_a_lead_from_a_different_day_untouched(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id'   => '1373288',
            'customer_name'      => 'Yesterdays Customer',
            'product_id'         => $product->id,
            'status'             => 'assigned',
            'pancake_created_at' => now()->subDay(),
        ]);

        Http::fake();

        Artisan::call('pancake:backfill-remove-logistics-leads');

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '1373288']);
        Http::assertNothingSent();
    }

    public function test_a_connection_failure_on_one_lead_does_not_stop_others_in_the_same_batch(): void
    {
        $product = Product::first();
        Lead::create(['pancake_order_id' => '1373287', 'product_id' => $product->id, 'status' => 'assigned', 'pancake_created_at' => now()]);
        Lead::create(['pancake_order_id' => '1373289', 'product_id' => $product->id, 'status' => 'assigned', 'pancake_created_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1373287*' => Http::response('boom', 500),
            'pos.pages.fm/api/v1/shops/4/orders/1373289*' => Http::response(['data' => [
                'id' => 1373289, 'creator' => ['id' => 'staff-1', 'name' => 'Ralph Cruz'],
            ]], 200),
        ]);

        $this->artisan('pancake:backfill-remove-logistics-leads')->assertSuccessful();

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '1373287']);
        $this->assertDatabaseMissing('leads', ['pancake_order_id' => '1373289']);
    }

    /** Explicit request, 2026-09-28: a --dry-run pass reports exactly what
     *  WOULD be removed without actually deleting anything, so it's safe to
     *  preview against real production data before running for real. */
    public function test_dry_run_reports_what_would_be_removed_without_deleting_anything(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id'   => '1373292',
            'product_id'         => $product->id,
            'status'             => 'assigned',
            'pancake_created_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1373292*' => Http::response(['data' => [
                'id' => 1373292, 'creator' => ['id' => 'staff-1', 'name' => 'AJ Dela Cruz'],
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-remove-logistics-leads', ['--dry-run' => true]);

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '1373292']);
        $this->assertStringContainsString('Would remove', Artisan::output());
    }

    /** Explicit follow-up, 2026-09-28: checking specific order ids directly
     *  (bypassing the date window) so the exact 4 real production orders
     *  reported can be previewed/removed regardless of what date they
     *  actually landed on locally. */
    public function test_order_option_checks_only_the_given_order_ids_regardless_of_date(): void
    {
        $product = Product::first();
        Lead::create(['pancake_order_id' => '1373293', 'product_id' => $product->id, 'status' => 'assigned', 'pancake_created_at' => now()->subDays(5)]);
        Lead::create(['pancake_order_id' => '9999', 'product_id' => $product->id, 'status' => 'assigned', 'pancake_created_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1373293*' => Http::response(['data' => [
                'id' => 1373293, 'creator' => ['id' => 'staff-1', 'name' => 'AJ Dela Cruz'],
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-remove-logistics-leads', ['--order' => '1373293']);

        $this->assertDatabaseMissing('leads', ['pancake_order_id' => '1373293']);
        // The other lead was never a candidate at all — --order scopes
        // the check to only the ids given, the unrelated lead is untouched
        // and no request for it was ever made.
        $this->assertDatabaseHas('leads', ['pancake_order_id' => '9999']);
    }

    /** The --order option must still respect the "leave already-worked
     *  leads alone" rule — same as the date-window path. */
    public function test_order_option_still_skips_an_already_called_lead(): void
    {
        $product = Product::first();
        Lead::create([
            'pancake_order_id' => '1373293', 'product_id' => $product->id,
            'status' => 'called', 'called_at' => now(), 'disposition' => 'callback',
        ]);

        Http::fake();

        Artisan::call('pancake:backfill-remove-logistics-leads', ['--order' => '1373293']);

        $this->assertDatabaseHas('leads', ['pancake_order_id' => '1373293']);
        Http::assertNothingSent();
    }
}
