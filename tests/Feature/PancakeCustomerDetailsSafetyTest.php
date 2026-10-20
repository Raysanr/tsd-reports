<?php

namespace Tests\Feature;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\PancakeOrderTagApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-20, explicit request: "no chance to auto delete the addresses or
 * whatsoever" — after "API (For TSD)" reverted staff address/note edits in
 * live Pancake (#1380806, #1380173, #1380141).
 */
class PancakeCustomerDetailsSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    private function fakeOrder(array $order): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['data' => [['id' => 7, 'name' => 'GEMMA']]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/1*' => Http::response(['success' => true, 'data' => array_merge(['id' => 1, 'tags' => []], $order)], 200),
        ]);
    }

    public function test_address_and_notes_writes_are_refused_without_a_signed_in_person(): void
    {
        $this->fakeOrder([]);
        $api = app(PancakeOrderTagApi::class);

        $this->assertFalse($api->updateShippingAddress('1', ['address' => 'anything']));
        $this->assertFalse($api->updateNotes('1', 'anything', null));
        Http::assertNothingSent();
    }

    public function test_address_write_still_works_for_a_signed_in_person(): void
    {
        $this->fakeOrder(['shipping_address' => ['address' => 'old']]);
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(app(PancakeOrderTagApi::class)->updateShippingAddress('1', ['address' => 'new']));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['shipping_address']['address'] === 'new');
    }

    public function test_automatic_tag_write_backs_off_from_an_order_edited_moments_ago(): void
    {
        $this->fakeOrder(['updated_at' => now('UTC')->subSeconds(30)->format('Y-m-d\TH:i:s')]);
        $api = app(PancakeOrderTagApi::class);

        $this->assertSame(['GEMMA' => false], $api->addTagsToOrder('1', ['GEMMA'], 180));
        $this->assertTrue($api->lastWriteSkippedRecentEdit);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_automatic_tag_write_goes_ahead_once_the_order_is_quiet(): void
    {
        $this->fakeOrder(['updated_at' => now('UTC')->subMinutes(10)->format('Y-m-d\TH:i:s')]);
        $api = app(PancakeOrderTagApi::class);

        $api->addTagsToOrder('1', ['GEMMA'], 180);
        $this->assertFalse($api->lastWriteSkippedRecentEdit);
        Http::assertSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_a_skipped_automatic_tsa_tag_is_queued_for_the_reconcile_instead_of_lost(): void
    {
        $this->fakeOrder(['updated_at' => now('UTC')->subSeconds(20)->format('Y-m-d\TH:i:s')]);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'T', 'product_id' => Product::first()->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::factory()->create(['pancake_order_id' => '1']);

        LeadController::tagTsaOnPancakeOrder($lead, app(PancakeOrderTagApi::class), [], true);

        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
        $this->assertNotEmpty(Order::where('pancake_order_id', '1')->first()->app_added_tags);
    }
}
