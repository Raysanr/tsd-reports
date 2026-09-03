<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
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
     * Reversed (explicit follow-up request, 2026-09-03: "when there's new
     * leads it is auto tagging ... because it is their leads") — round-robin
     * assignment now pushes the new owner's own POS name tag to Pancake
     * immediately, same as LeadController::tagOutcomeInPancake() does when an
     * outcome is logged, via the shared tagTsaOnPancakeOrder() helper. Was
     * previously a local-only signal with no tag write-back at all.
     */
    public function test_a_new_leads_round_robin_assignment_tags_the_new_owner_in_pancake(): void
    {
        $this->fakePancake([[
            'id'    => 9004,
            'bill_full_name'  => 'Tag Check',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]], [
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 11, 'name' => 'Gemma'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/*/orders/9004*' => Http::response(['success' => true, 'data' => ['id' => 9004, 'tags' => []]], 200),
        ]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9004')->first();
        $this->assertSame('assigned', $lead->status);
        $this->assertSame('Gemma', $lead->tsa->tsa_key);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT' || !str_contains($r->url(), '/orders/9004')) return false;
            return collect($r['tags'])->pluck('name')->contains('Gemma');
        });
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

    /**
     * Explicit request (2026-08-26): Pancake sometimes creates two separate
     * orders for what's really the same customer inquiry — confirmed live,
     * orders #1357483/#1357480, same phone, same product, both landing on
     * the same TSA purely by round-robin coincidence. Same phone (last 9
     * digits) + same matched product + same calendar day (Asia/Manila) now
     * auto-routes to whoever already has the first one, instead of spending
     * a fresh round-robin slot on what's probably not a genuinely new lead.
     */
    public function test_a_same_day_same_phone_same_product_order_routes_to_the_existing_tsa_not_a_fresh_round_robin_pick(): void
    {
        // All three in ONE batch, same shape a real sync page fetch already
        // returns — Http::fake() re-registering the same URL pattern mid-test
        // doesn't reliably override the prior stub, so every case in this
        // file that needs multiple orders present at once uses one fake
        // response instead of re-faking between separate Artisan::call()s.
        $this->fakePancake([
            ['id' => 9101, 'bill_full_name' => 'M Jr Barbarona', 'bill_phone_number' => '09850050211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]], 'inserted_at' => now()->toIso8601String()],
            // A differently-formatted but same real number, same product, same day.
            ['id' => 9102, 'bill_full_name' => 'M Jr barbarona', 'bill_phone_number' => '(0985) 005-0211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]], 'inserted_at' => now()->toIso8601String()],
            // A genuinely new lead (different phone) — must still get the
            // SECOND rotation slot (Mariel), proving the duplicate never
            // consumed one of its own.
            ['id' => 9103, 'bill_full_name' => 'Someone Else', 'bill_phone_number' => '09991112222',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]], 'inserted_at' => now()->toIso8601String()],
        ]);
        Artisan::call('pancake:sync-leads');

        $first  = Lead::where('pancake_order_id', '9101')->first();
        $second = Lead::where('pancake_order_id', '9102')->first();
        $third  = Lead::where('pancake_order_id', '9103')->first();

        $this->assertSame('Gemma', $first->tsa->tsa_key); // first in SINUXYL's rotation
        $this->assertSame('assigned', $second->status);
        $this->assertSame('Gemma', $second->tsa->tsa_key); // same TSA, not the next in rotation
        $this->assertSame('Mariel', $third->tsa->tsa_key); // the SECOND rotation slot, untouched by the duplicate

        $activity = LeadActivity::where('lead_id', $second->id)->where('type', 'assigned')->first();
        $this->assertStringContainsString('Likely duplicate of order #9101', $activity->description);
    }

    public function test_a_different_product_on_the_same_day_and_phone_is_not_treated_as_a_duplicate(): void
    {
        $this->fakePancake([
            ['id' => 9201, 'bill_full_name' => 'First Order', 'bill_phone_number' => '09850050211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]], 'inserted_at' => now()->toIso8601String()],
            ['id' => 9202, 'bill_full_name' => 'Second Order', 'bill_phone_number' => '09850050211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'AudiCure']]], 'inserted_at' => now()->toIso8601String()],
        ]);
        Artisan::call('pancake:sync-leads');

        $second = Lead::where('pancake_order_id', '9202')->first();
        $this->assertNotNull($second);
        // AudiCure's own rotation starts fresh at Gemma too — the point is
        // this ISN'T logged as a duplicate, not which TSA it lands on.
        $activity = LeadActivity::where('lead_id', $second->id)->where('type', 'assigned')->first();
        $this->assertStringNotContainsString('Likely duplicate', $activity->description);
    }

    public function test_a_same_phone_same_product_order_from_a_different_day_is_not_treated_as_a_duplicate(): void
    {
        $this->fakePancake([
            ['id' => 9301, 'bill_full_name' => 'Yesterday Order', 'bill_phone_number' => '09850050211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
                'inserted_at' => now('Asia/Manila')->subDay()->toIso8601String()],
            ['id' => 9302, 'bill_full_name' => 'Today Order', 'bill_phone_number' => '09850050211',
                'tags' => [], 'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
                'inserted_at' => now('Asia/Manila')->toIso8601String()],
        ]);
        Artisan::call('pancake:sync-leads', ['--hours' => 48]);

        $second = Lead::where('pancake_order_id', '9302')->first();
        $this->assertNotNull($second);
        $activity = LeadActivity::where('lead_id', $second->id)->where('type', 'assigned')->first();
        $this->assertStringNotContainsString('Likely duplicate', $activity->description);
    }

    /** An "original" that's still itself unassigned has nothing to route a
     *  duplicate to — the new order falls through to a normal round-robin
     *  pick instead, same as if no duplicate existed at all. Genuinely needs
     *  two separate Artisan::call()s (the roster changes in between), so
     *  this uses Http::sequence() instead of re-faking the same URL pattern
     *  mid-test — see the main duplicate test's own comment on why a plain
     *  second fakePancake() call doesn't reliably override the first. */
    public function test_a_duplicate_of_a_still_unassigned_lead_falls_through_to_normal_round_robin(): void
    {
        // No product_tsa roster for AudiCure in this test, so the first
        // order lands unassigned — nothing for the second to inherit.
        Product::where('display_name', 'AUDICURE')->first()->tsas()->detach();

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::sequence()
                ->push(['data' => [
                    ['id' => 9401, 'bill_full_name' => 'Unassigned Original', 'bill_phone_number' => '09850050211',
                        'tags' => [], 'items' => [['variation_info' => ['name' => 'AudiCure']]], 'inserted_at' => now()->toIso8601String()],
                ]], 200)
                ->push(['data' => [
                    ['id' => 9402, 'bill_full_name' => 'Second Order Same Phone', 'bill_phone_number' => '09850050211',
                        'tags' => [], 'items' => [['variation_info' => ['name' => 'AudiCure']]], 'inserted_at' => now()->toIso8601String()],
                ]], 200),
        ]);

        Artisan::call('pancake:sync-leads');
        $first = Lead::where('pancake_order_id', '9401')->first();
        $this->assertSame('unassigned', $first->status);

        Product::where('display_name', 'AUDICURE')->first()->tsas()->attach(
            TsaShift::where('tsa_key', 'Gemma')->first()->id
        );

        Artisan::call('pancake:sync-leads');
        $second = Lead::where('pancake_order_id', '9402')->first();

        $this->assertSame('assigned', $second->status);
        $activity = LeadActivity::where('lead_id', $second->id)->where('type', 'assigned')->first();
        $this->assertStringNotContainsString('Likely duplicate', $activity->description);
    }
}
