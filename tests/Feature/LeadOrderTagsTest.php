<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit request (2026-08-22, from a screenshot of Pancake POS's own tag
 * chips): show the order's REAL current tags on the Leads tab — distinct from
 * the disposition-picker's own staged outcome selection, which only reflects
 * what a TSA is choosing to log, not everything already on the order — and
 * let a TSA remove one, writing back to Pancake the same GET-then-PUT way
 * every other order mutation on this page does.
 */
class LeadOrderTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    private function leadFor(TsaShift $tsa, string $orderId = '9001'): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Juan', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
    }

    public function test_the_leads_table_shows_a_compact_tag_count_badge_carrying_the_real_tags_and_colors(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create([
            'pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0,
            'raw_tags' => ['GEMMA', 'NOT ANSWERING - EYECARE'], 'synced_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 1, 'name' => 'GEMMA', 'color' => '#123456'],
                ['id' => 2, 'name' => 'NOT ANSWERING - EYECARE', 'color' => '#abcdef'],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('real-tags-badge', false);
        // Real-tag data now travels in the badge's data-tags JSON, not inline
        // chips (follow-up fix, 2026-08-22: inline chips made a row with several
        // tags taller than every other row) — the panel that renders them is
        // populated client-side from this payload, so its presence here is what
        // proves the read side actually works.
        $response->assertSee('GEMMA');
        $response->assertSee('NOT ANSWERING - EYECARE');
        $response->assertSee('#123456', false);
    }

    public function test_removing_a_tag_writes_it_back_to_the_real_order_and_the_local_cache(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create([
            'pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0,
            'raw_tags' => ['GEMMA', 'NOT ANSWERING - EYECARE'], 'synced_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'tags' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'NOT ANSWERING - EYECARE'],
                ],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.remove', $lead), ['tag' => 'NOT ANSWERING - EYECARE']);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && count($r['tags']) === 1
            && $r['tags'][0]['name'] === 'GEMMA');

        $this->assertSame(['GEMMA'], Order::where('pancake_order_id', '9001')->value('raw_tags'));
    }

    public function test_a_tsa_cannot_remove_a_tag_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.remove', $lead), ['tag' => 'GEMMA']);

        $response->assertForbidden();
    }

    public function test_remove_tag_is_rejected_when_the_lead_has_no_linked_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.remove', $lead), ['tag' => 'GEMMA']);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** Explicit follow-up request (2026-08-25): an inline "+ Add tag" chip
     *  right in the POS Tags panel, writing a real tag straight to Pancake
     *  — same GET-then-PUT-whole-order write as removeTag() above, mirror
     *  image of that test. */
    public function test_adding_a_tag_writes_it_to_the_real_order_and_the_local_cache(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create([
            'pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0,
            'raw_tags' => ['GEMMA'], 'synced_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 1, 'name' => 'GEMMA'],
                ['id' => 2, 'name' => 'SCAR CREAM'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::sequence()
                ->push(['success' => true, 'data' => ['id' => 9001, 'tags' => [['id' => 1, 'name' => 'GEMMA']]]], 200)
                ->push(['success' => true], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.add', $lead), ['tag' => 'SCAR CREAM']);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && count($r['tags']) === 2
            && collect($r['tags'])->pluck('name')->contains('SCAR CREAM'));

        $this->assertSame(['GEMMA', 'SCAR CREAM'], Order::where('pancake_order_id', '9001')->value('raw_tags'));
    }

    public function test_a_tsa_cannot_add_a_tag_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.add', $lead), ['tag' => 'SCAR CREAM']);

        $response->assertForbidden();
    }

    public function test_add_tag_is_rejected_when_the_lead_has_no_linked_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '', 'customer_name' => 'Juan', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.add', $lead), ['tag' => 'SCAR CREAM']);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** A tag name that doesn't match anything in Pancake's own real catalog
     *  must fail cleanly, not silently no-op as if it succeeded — same
     *  "never invent a tag the catalog doesn't have" guarantee
     *  addTagsToOrder() itself already enforces. */
    public function test_add_tag_fails_when_the_name_matches_nothing_in_the_real_catalog(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create(['pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0, 'raw_tags' => [], 'synced_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 1, 'name' => 'GEMMA'],
            ]], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.add', $lead), ['tag' => 'NOT A REAL TAG']);

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame([], Order::where('pancake_order_id', '9001')->value('raw_tags'));
    }

    public function test_remove_tag_returns_an_error_and_leaves_the_local_cache_untouched_when_the_pancake_write_fails(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);
        Order::create([
            'pancake_order_id' => '9001', 'team' => 'SH Naturals', 'status_code' => 0,
            'raw_tags' => ['GEMMA'], 'synced_at' => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.tags.remove', $lead), ['tag' => 'GEMMA']);

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame(['GEMMA'], Order::where('pancake_order_id', '9001')->value('raw_tags'));
    }
}
