<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) — unmodified
 * (no Tsa/route references beyond Lead::tsa relation, which already points
 * at TsaShift).
 *
 * NOTE (adapted, not verbatim): SyncPancakeLeads round-robin-assigns via
 * RoundRobinAssigner::next(), which reads Product::tsas() (product_tsa).
 * Unlike call-tracker's original migrations (which seeded that table
 * directly), the merged app's product_tsa is deliberately NOT seeded by any
 * migration — it's wired up by the one-time `calltracker:reconcile-roster`
 * command (Phase 4). Added to setUp() below so every test still gets the
 * same "Gemma is first in SINUXYL's rotation" seeded assignment order the
 * original tests assumed.
 */
class SyncPancakeLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
        Setting::set('pancake_access_token', 'fake-user-access-token');
        $this->artisan('calltracker:reconcile-roster');
    }

    private function fakePancake(array $orders, array $overrides = []): void
    {
        Http::fake(array_merge([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response(['data' => $orders], 200),
        ], $overrides));
    }

    public function test_an_unclaimed_order_gets_pulled_in_and_round_robin_assigned(): void
    {
        $this->fakePancake([[
            'id'               => 9001,
            // Real Pancake shape (confirmed against a live order fetched
            // during this session): no top-level 'name'/'phone_number' at
            // all — bill_full_name/bill_phone_number are the real fields.
            'bill_full_name'   => 'Juan Dela Cruz',
            'bill_phone_number' => '09171234567',
            'tags'        => [],
            'items'       => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
            'customer'    => ['conversation_link' => 'https://pancake.vn/123456789?customer_id=abc-123'],
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9001')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Juan Dela Cruz', $lead->customer_name);
        $this->assertSame('09171234567', $lead->phone_number);
        $this->assertSame('https://pancake.vn/123456789?customer_id=abc-123', $lead->conversation_link);
        $this->assertSame('assigned', $lead->status);
        $this->assertSame('Gemma', $lead->tsa->tsa_key); // first in SINUXYL's rotation
        $this->assertSame('SINUXYL', $lead->product->display_name);
    }

    public function test_an_order_with_no_conversation_link_still_syncs_fine(): void
    {
        $this->fakePancake([[
            'id'   => 9007,
            'bill_full_name' => 'No Conversation Link',
            'tags' => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9007')->first();
        $this->assertNotNull($lead);
        $this->assertNull($lead->conversation_link);
    }

    /**
     * Root-cause regression test: bill_phone_number and bill_full_name are
     * the primary source, but a real order missing them (e.g. an older
     * record) still has the customer's own phone/name on file — this must
     * fall back to customer.name / customer.phone_numbers[0] (plural array,
     * NOT the singular customer.phone_number this code used to guess).
     */
    public function test_a_missing_bill_name_and_phone_falls_back_to_the_customer_record(): void
    {
        $this->fakePancake([[
            'id'    => 9006,
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
            'customer' => [
                'name'          => 'Marites Listor',
                'phone_numbers' => ['09815159190'],
            ],
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9006')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Marites Listor', $lead->customer_name);
        $this->assertSame('09815159190', $lead->phone_number);
    }

    public function test_an_order_already_carrying_a_known_tsas_tag_is_left_alone(): void
    {
        $this->fakePancake([[
            'id'    => 9002,
            'bill_full_name'  => 'Already Claimed',
            'tags'  => [['id' => 1, 'name' => 'MARIEL']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $this->assertDatabaseMissing('leads', ['pancake_order_id' => '9002']);
    }

    public function test_an_order_matching_no_known_product_is_pulled_in_as_unassigned(): void
    {
        $this->fakePancake([[
            'id'    => 9003,
            'bill_full_name'  => 'Mystery Product Buyer',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Some Other Thing']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9003')->first();
        $this->assertNotNull($lead);
        $this->assertSame('unassigned', $lead->status);
        $this->assertNull($lead->tsa_id);
    }

    /**
     * Explicit request: round-robin assignment is a local-only signal now —
     * no tag write-back to Pancake happens automatically when a new lead
     * comes in. Only a TSA's own logged Outcome writes real tags (see
     * LeadController::tagOutcomeInPancake()).
     */
    public function test_a_new_leads_round_robin_assignment_does_not_write_any_tag_to_pancake(): void
    {
        $this->fakePancake([[
            'id'    => 9004,
            'bill_full_name'  => 'Tag Check',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9004')->first();
        $this->assertSame('assigned', $lead->status);
        $this->assertSame('Gemma', $lead->tsa->tsa_key);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/orders/9004') || str_contains($r->url(), '/orders/tags'));
    }

    public function test_running_the_sync_twice_does_not_reassign_or_duplicate_a_lead(): void
    {
        $order = [
            'id'    => 9005,
            'bill_full_name'  => 'Idempotency Check',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ];
        $this->fakePancake([$order]);

        Artisan::call('pancake:sync-leads');
        $firstTsa = Lead::where('pancake_order_id', '9005')->first()->tsa->tsa_key;

        Artisan::call('pancake:sync-leads');

        $this->assertSame(1, Lead::where('pancake_order_id', '9005')->count());
        $this->assertSame($firstTsa, Lead::where('pancake_order_id', '9005')->first()->tsa->tsa_key);
    }
}
