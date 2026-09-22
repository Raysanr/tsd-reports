<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
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

    /**
     * Regression test — root-caused live on production, 2026-09-18: "why is
     * it like no leads distributed in the leads page?" at 07:41 AM Manila
     * time, with real Pancake orders confirmed arriving (Order rows synced
     * fine) but the Leads page ("today's" leads, filtered by
     * pancake_created_at) showed none.
     *
     * Confirmed via direct DB comparison: the SAME real order's
     * Order.pancake_created_at read 2026-09-18 07:41:56 (correct Manila
     * time) while the matching Lead.pancake_created_at read
     * 2026-09-17 23:41:56 — exactly 8 hours earlier, landing on the
     * PREVIOUS calendar day. Root cause: this line parsed Pancake's raw
     * inserted_at (a bare UTC string with NO offset marker — see
     * SyncTodayOrders::flushOrders()'s own "Fix 1: Pancake stores UTC
     * without TZ marker — parse as UTC, convert to Manila" comment, the
     * SAME fix that command already has) as UTC but never converted it to
     * Manila time before saving — so the raw UTC clock-time was stored
     * as-is. Every Manila morning before 8:00 AM, a genuinely-today order
     * landed with a pancake_created_at still reading as "yesterday" to
     * every view that filters on it (Leads, Overdue, Callbacks, the
     * sidebar badge, Monitor, Dashboard, Analytics — all of them).
     *
     * This test uses a BARE datetime string (no +08:00/Z suffix), matching
     * real Pancake data and SyncTodayOrders's own test fixtures
     * (SyncTodayOrdersBacklogTest.php) — every OTHER test in this file
     * uses now()->toIso8601String(), which embeds an explicit offset
     * Carbon::parse() respects regardless of the 'UTC' argument, so none
     * of them ever exercised the real "offset-less string" case that
     * actually triggers this bug in production.
     */
    public function test_pancake_created_at_is_stored_in_manila_time_not_raw_utc_clock_time(): void
    {
        // 23:41:56 UTC = 07:41:56 the NEXT calendar day in Manila (UTC+8) —
        // the exact real-world case that surfaced this bug.
        $this->fakePancake([[
            'id'               => 9010,
            'bill_full_name'   => 'Early Morning Order',
            'bill_phone_number' => '09171234568',
            'tags'        => [],
            'items'       => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => '2026-09-17 23:41:56',
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9010')->first();
        $this->assertNotNull($lead);
        $this->assertSame(
            '2026-09-18 07:41:56',
            $lead->pancake_created_at->toDateTimeString(),
            'pancake_created_at must be converted to Asia/Manila time, not left as the raw UTC clock-time — '
                . 'a bare UTC 23:41:56 order is 07:41:56 the NEXT day in Manila.'
        );
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

    /**
     * Explicit report, 2026-09-19: "is it possible that can be not
     * distribute the leads that is duplicated from logistics? like from AJ
     * DELA CRUZ and RALPH CRUZ" — two separate real orders, each already
     * carrying Pancake's own "DUPLICATED BY LOGISTICS" note (staff-written
     * when a warehouse/logistics duplicate creates a second order for the
     * same real lead), still got pulled in as Leads and round-robin
     * assigned to a TSA to call. Same live-note check
     * Order::isDuplicatedByLogistics() already applies at Order-sync time
     * and at reporting time, now also applied here before a Lead is ever
     * created — a duplicate should never reach a TSA's queue at all.
     */
    public function test_an_order_flagged_duplicated_by_logistics_is_not_pulled_in_as_a_lead(): void
    {
        $this->fakePancake([[
            'id'              => 9099,
            'bill_full_name'  => 'Ralph Cruz',
            'tags'            => [],
            'items'           => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at'     => now()->toIso8601String(),
            'note'            => 'DUPLICATED BY LOGISTICS',
        ]]);

        Artisan::call('pancake:sync-leads');

        $this->assertDatabaseMissing('leads', ['pancake_order_id' => '9099']);
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

    /**
     * Regression test, 2026-09-16: root-caused live in two passes. First
     * looked like Kathleen/Grace Olivo/Angel Margallo's tsa_key just had no
     * matching Pancake tag at all ("why now there's a leads that is not
     * auto tagging like tsa tag name like that but there's sometimes that
     * is auto tagging"). A follow-up screenshot of the real POS tag search
     * ("there's angel in the POS... it should be angel") corrected that:
     * their real Pancake tag is a DIFFERENT alias than tsa_key (same shape
     * GoogleDriveClient::folderBelongsToTsa() already root-caused
     * 2026-09-08 for Drive folder matching) — resolveTsaTagName() now tries
     * every one of the TSA's tag_keywords aliases before ever creating a
     * new tag. This test proves the "genuinely no alias matches anything"
     * last-resort path still works.
     */
    public function test_a_tsa_with_no_matching_tag_under_any_alias_gets_a_new_tag_created(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['tag_keywords' => 'GEMMA']);

        $this->fakePancake([[
            'id'    => 9010,
            'bill_full_name'  => 'New Tag Check',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]], [
            // Http::sequence() consumes in call order regardless of
            // method, and GET+POST both hit /orders/tags: (1)
            // resolveTsaTagName()'s own listTags() GET — empty, no alias
            // matches; (2) createTagIfMissing()'s POST that creates the
            // tag and busts the cache; (3) addTagsToOrder()'s listTags()
            // GET, now fresh again since the cache was just forgotten.
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::sequence()
                ->push(['success' => true, 'data' => []], 200)
                ->push(['success' => true, 'data' => ['id' => 99, 'name' => 'Gemma']], 200)
                ->push(['success' => true, 'data' => [['id' => 99, 'name' => 'Gemma']]], 200),
            'pos.pages.fm/api/v1/shops/*/orders/9010*' => Http::response(['success' => true, 'data' => ['id' => 9010, 'tags' => []]], 200),
        ]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9010')->first();
        $this->assertSame('assigned', $lead->status);
        $this->assertSame('Gemma', $lead->tsa->tsa_key);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['name'] ?? null) === 'Gemma');
        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT' || !str_contains($r->url(), '/orders/9010')) return false;
            return collect($r['tags'])->pluck('name')->contains('Gemma');
        });
    }

    /**
     * The actual fix this session's report was about: a TSA whose tsa_key
     * itself has no matching Pancake tag, but one of her OTHER
     * tag_keywords aliases does (Angel Margallo's real shape: tsa_key
     * "Angelica" has 851 historical orders, but her real Pancake tag is
     * "ANGEL" — see resolveTsaTagName()'s own doc comment) — the real
     * existing alias tag wins, no redundant new tag is ever created for
     * her.
     */
    public function test_a_tsa_whose_real_tag_is_a_keyword_alias_not_her_tsa_key_is_tagged_correctly(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['tag_keywords' => 'GEMMA,GEM']);

        $this->fakePancake([[
            'id'    => 9011,
            'bill_full_name'  => 'Alias Tag Check',
            'tags'  => [],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]], [
            // "Gemma" (tsa_key) has no match, but "GEM" (a tag_keywords
            // alias) does — real production shape for Angel/"ANGEL".
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::response(['success' => true, 'data' => [
                ['id' => 42, 'name' => 'GEM'],
            ]], 200),
            'pos.pages.fm/api/v1/shops/*/orders/9011*' => Http::response(['success' => true, 'data' => ['id' => 9011, 'tags' => []]], 200),
        ]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9011')->first();
        $this->assertSame('assigned', $lead->status);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT' || !str_contains($r->url(), '/orders/9011')) return false;
            return collect($r['tags'])->pluck('name')->contains('GEM');
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

    /**
     * Regression test, 2026-09-14: "why mariel is currently online today
     * but no leads" — root-caused: the catch-up sweep used to process
     * EVERY unassigned lead with no limit, and after a backlog grew to
     * thousands of leads (a mass-dump incident the same day, see
     * RoundRobinAssigner's own comment), a single run's real cost —
     * dominated by tagTsaOnPancakeOrder()'s live Pancake API call per
     * successful assignment — started taking minutes, so most sync ticks
     * just skipped with "already running" and round-robin couldn't reach
     * every eligible TSA in a timely way. Bounded to
     * SyncPancakeLeads::CATCH_UP_BATCH_LIMIT oldest leads per run instead —
     * confirmed here that with a backlog bigger than the batch limit, only
     * the oldest ones get swept up in one run, and the normal round-robin
     * rotation still divides them fairly among whoever's eligible within
     * that batch (explicit follow-up, same conversation: "i want in all
     * tsa when they are login and has same product it should be like
     * equally divided of leads" — confirmed this fairness is unaffected by
     * the batch limit, only how MANY get processed per run changes).
     */
    public function test_the_catch_up_sweep_is_bounded_to_a_batch_and_still_divides_fairly(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();

        // More unassigned leads than the batch limit, oldest first.
        $total = 210;
        for ($i = 0; $i < $total; $i++) {
            Lead::create([
                'pancake_order_id' => "batch-{$i}", 'customer_name' => "Backlog {$i}",
                'product_id' => $product->id, 'status' => 'unassigned',
                'pancake_created_at' => now()->subDays(2)->addMinutes($i),
            ]);
        }

        // Both TSAs have been online well past the handover buffer (see
        // RoundRobinAssigner::GAP_BUFFER_MINUTES) — this test is about
        // batch-limit bounding, not the handover-fairness buffer itself
        // (see test_catch_up_holds_a_products_backlog_until_the_handover_
        // buffer_elapses below for that).
        RoundRobinState::updateOrCreate(
            ['product_id' => $product->id],
            ['roster_available_since' => now()->subMinutes(10)]
        );

        Http::fake(['pos.pages.fm/api/v1/*' => Http::response(['success' => true], 200)]);

        Artisan::call('pancake:sync-leads');

        $assignedCount = Lead::whereIn('pancake_order_id', array_map(fn ($i) => "batch-{$i}", range(0, $total - 1)))
            ->where('status', 'assigned')->count();

        // Only up to the batch limit got processed this run — the rest
        // stay unassigned, waiting for the next tick.
        $this->assertLessThanOrEqual(200, $assignedCount);
        $this->assertGreaterThan(0, $assignedCount);

        // The oldest ones (lowest index) are the ones that got processed —
        // still oldest-first within the batch, same fairness ordering as
        // before.
        $this->assertSame('assigned', Lead::where('pancake_order_id', 'batch-0')->first()->status);

        // Normal round-robin rotation still divides the processed batch
        // between both eligible TSAs, not all landing on one.
        $gemmaCount  = Lead::where('tsa_id', $gemma->id)->whereIn('pancake_order_id', array_map(fn ($i) => "batch-{$i}", range(0, $total - 1)))->count();
        $marielCount = Lead::where('tsa_id', $mariel->id)->whereIn('pancake_order_id', array_map(fn ($i) => "batch-{$i}", range(0, $total - 1)))->count();
        $this->assertGreaterThan(0, $gemmaCount);
        $this->assertGreaterThan(0, $marielCount);
    }

    /**
     * Shift-handover fairness (explicit request, 2026-09-17: "there's tsa
     * that is login in 6am so all of leads will his/her... 7am that got
     * login... is it onwards... it should not be bulk all redistribute to
     * the one tsa that got first login") — a product's backlog of
     * unassigned leads must NOT get swept up the instant the first TSA
     * of the day/shift logs in; it should wait
     * RoundRobinAssigner::GAP_BUFFER_MINUTES so the rest of the
     * closing/opening team has a chance to log in too. Only Gemma is
     * online here — the whole SINUXYL backlog stays unassigned for this
     * run since her roster_available_since is still inside the buffer.
     */
    public function test_catch_up_holds_a_products_backlog_until_the_handover_buffer_elapses(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::where('tsa_key', '!=', 'Gemma')->update(['status' => TsaShift::STATUS_LOGOUT]);

        Lead::create([
            'pancake_order_id' => 'gap-1', 'customer_name' => 'Overnight Lead',
            'product_id' => $product->id, 'status' => 'unassigned',
            'pancake_created_at' => now()->subHours(3),
        ]);

        Http::fake(['pos.pages.fm/api/v1/*' => Http::response(['success' => true], 200)]);

        // Gemma's own first sync tick — RoundRobinAssigner::trackRosterAvailability()
        // stamps roster_available_since = now() for SINUXYL right here, since
        // the roster just went from empty (everyone else logged out above) to
        // non-empty (Gemma alone). The lead created above is unrelated to
        // WHEN it went unassigned — the buffer only measures the roster.
        Artisan::call('pancake:sync-leads');

        $this->assertSame('unassigned', Lead::where('pancake_order_id', 'gap-1')->first()->status);
    }

    /**
     * Companion to the buffer test above: once the buffer has elapsed, the
     * backlog releases and divides fairly across whoever logged in within
     * that window — Mariel joining a couple minutes after Gemma still gets
     * her fair share instead of Gemma having already absorbed everything.
     */
    public function test_catch_up_divides_a_gaps_backlog_fairly_once_the_buffer_elapses(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        TsaShift::where('tsa_key', '!=', 'Gemma')->where('tsa_key', '!=', 'Mariel')->update(['status' => TsaShift::STATUS_LOGOUT]);

        for ($i = 0; $i < 6; $i++) {
            Lead::create([
                'pancake_order_id' => "gap2-{$i}", 'customer_name' => "Overnight Lead {$i}",
                'product_id' => $product->id, 'status' => 'unassigned',
                'pancake_created_at' => now()->subHours(3)->addMinutes($i),
            ]);
        }

        // Both Gemma and Mariel were already online past the buffer by the
        // time this run fires — simulates Gemma logging in at 6:00, Mariel
        // at 6:03, and this sync tick landing at 6:05 or later.
        RoundRobinState::updateOrCreate(
            ['product_id' => $product->id],
            ['roster_available_since' => now()->subMinutes(5)]
        );

        Http::fake(['pos.pages.fm/api/v1/*' => Http::response(['success' => true], 200)]);

        Artisan::call('pancake:sync-leads');

        $orderIds = array_map(fn ($i) => "gap2-{$i}", range(0, 5));
        $gemmaCount  = Lead::where('tsa_id', $gemma->id)->whereIn('pancake_order_id', $orderIds)->count();
        $marielCount = Lead::where('tsa_id', $mariel->id)->whereIn('pancake_order_id', $orderIds)->count();
        $this->assertGreaterThan(0, $gemmaCount);
        $this->assertGreaterThan(0, $marielCount);
        $this->assertSame(6, $gemmaCount + $marielCount);
    }

    /**
     * Originally root-caused 2026-09-08 (explicit request: "all of the
     * leads in the pos that has unattended and not answering tag is
     * should be display in the callbacks page") against Not Answering/
     * Unattended Pancake tags. Updated 2026-09-22 for the
     * CALLBACK_TRIGGER_KEYWORDS reversal (see that constant's own doc
     * comment) — the mechanism under test (a lead someone tagged directly
     * in Pancake, not through this app's own Log Outcome flow, still
     * picks up a callback_at) is unchanged, only which tag now matches.
     * This is the every-minute sync's own re-check of an
     * ALREADY-EXISTING lead's current tags, not the new-lead creation path
     * above.
     */
    public function test_an_existing_leads_pancake_tag_backfills_a_callback(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9501', 'customer_name' => 'Already Synced',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $this->fakePancake([[
            'id' => 9501, 'bill_full_name' => 'Already Synced', 'bill_phone_number' => '09171234567',
            'tags' => [['name' => 'CALL BACK']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertSame('CALL BACK', $lead->disposition);
        $this->assertNotNull($lead->callback_at);
        // Due NOW, not +1 day (root-caused 2026-09-08: an earlier version of
        // this fix used +1 day, the same fallback updateDisposition() uses
        // for a TSA who hasn't picked a time — but the Callbacks view only
        // ever shows callback_at <= now(), so every backfilled lead was
        // invisible on today's Callbacks page until the NEXT day).
        $this->assertTrue($lead->callback_at->lte(now()));
        // status/tsa_id untouched — this only ever fills in the callback,
        // never re-processes the lead as if it were brand new.
        $this->assertSame('assigned', $lead->status);
        $this->assertSame($gemma->id, $lead->tsa_id);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'callback_scheduled')->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Pancake tag', $activity->description);
    }

    /**
     * The actual end-to-end proof the earlier version of this fix lacked —
     * confirming the backfilled lead genuinely appears on the real
     * Callbacks page (not just that callback_at got set to SOME value).
     * This is the exact gap that let the +1-day bug ship: every unit-level
     * assertion above passed with the wrong due time, since none of them
     * hit the real view's own callback_at <= now() filter.
     */
    public function test_a_pancake_tag_backfilled_lead_actually_shows_on_the_callbacks_page(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => '9506', 'customer_name' => 'Should Appear Today',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $this->fakePancake([[
            'id' => 9506, 'bill_full_name' => 'Should Appear Today', 'bill_phone_number' => '09171234572',
            'tags' => [['name' => 'CALL BACK']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertOk();
        $response->assertSee('Should Appear Today');
    }

    /** Not Answering/Unattended Pancake tags no longer backfill a callback
     *  (reversed 2026-09-22 — see CALLBACK_TRIGGER_KEYWORDS' own doc
     *  comment) — they now belong to the Unanswered Calls page instead,
     *  which matches on disposition directly and never touches
     *  callback_at at all. */
    public function test_the_unattended_tag_no_longer_backfills_a_callback(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => '9502', 'customer_name' => 'Unattended Lead',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $this->fakePancake([[
            'id' => 9502, 'bill_full_name' => 'Unattended Lead', 'bill_phone_number' => '09171234568',
            'tags' => [['name' => 'Unattended']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead = Lead::where('pancake_order_id', '9502')->first();
        $this->assertNull($lead->callback_at);
    }

    public function test_a_lead_already_called_is_never_overwritten_by_a_pancake_tag(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9503', 'customer_name' => 'Already Called',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called',
            'disposition' => 'Confirmed via call',
        ]);

        $this->fakePancake([[
            'id' => 9503, 'bill_full_name' => 'Already Called', 'bill_phone_number' => '09171234569',
            'tags' => [['name' => 'CALL BACK']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertSame('Confirmed via call', $lead->disposition);
        $this->assertNull($lead->callback_at);
    }

    public function test_a_lead_with_an_existing_callback_is_never_rescheduled_by_a_pancake_tag(): void
    {
        $gemma    = TsaShift::where('tsa_key', 'Gemma')->first();
        $product  = Product::where('display_name', 'SINUXYL')->first();
        $original = now()->addHours(3);
        $lead = Lead::create([
            'pancake_order_id' => '9504', 'customer_name' => 'Has Callback',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called',
            'disposition' => 'Call Back', 'callback_at' => $original,
        ]);

        $this->fakePancake([[
            'id' => 9504, 'bill_full_name' => 'Has Callback', 'bill_phone_number' => '09171234570',
            'tags' => [['name' => 'CALL BACK']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertEquals($original->timestamp, $lead->callback_at->timestamp);
    }

    public function test_a_lead_with_no_matching_tag_is_left_untouched(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9505', 'customer_name' => 'No Matching Tag',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
        ]);

        $this->fakePancake([[
            'id' => 9505, 'bill_full_name' => 'No Matching Tag', 'bill_phone_number' => '09171234571',
            'tags' => [['name' => 'CONFIRMED VIA CALL']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertNull($lead->disposition);
        $this->assertNull($lead->callback_at);
    }

    /**
     * Root-caused 2026-09-08 (real production case: order #1365830 was
     * tagged "Not Answering" — correctly triggered a callback via
     * backfillCallbackFromTags() under the keyword set at the time — then
     * someone called it directly in Pancake and it's now tagged
     * "Confirmed Via Call" instead; the lead sat stuck showing as a due
     * callback forever on the Callbacks page, since nothing ever
     * re-checked it once the real tag moved on). Updated 2026-09-22 to use
     * "CALL BACK" as the initially-matching tag (CALLBACK_TRIGGER_KEYWORDS
     * reversal — see that constant's own doc comment); the clearing
     * behavior itself is unchanged. status stays non-'called' the whole
     * time here (this is the exact signal that distinguishes a
     * backfill-set callback from a TSA's own logged Outcome — see this
     * method's own doc comment) — a real TSA Log Outcome always sets
     * status='called' in the same write, so it can never be silently
     * cleared by this.
     */
    public function test_a_backfilled_callback_is_cleared_once_the_real_tag_no_longer_justifies_it(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => '9506', 'customer_name' => 'Now Confirmed',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'disposition' => 'CALL BACK', 'callback_at' => now(),
        ]);

        $this->fakePancake([[
            'id' => 9506, 'bill_full_name' => 'Now Confirmed', 'bill_phone_number' => '09171234572',
            'tags' => [['name' => 'CONFIRMED VIA CALL']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertNull($lead->disposition);
        $this->assertNull($lead->callback_at);
        // status/tsa_id untouched — clearing the callback doesn't turn
        // this into "called" on its own; a real TSA still needs to log
        // the actual outcome for that.
        $this->assertSame('assigned', $lead->status);
    }

    /**
     * The other half of the same fix: a TSA's own manually-scheduled
     * callback (status='called', a real logged Outcome via
     * updateDisposition()) must NEVER be auto-cleared just because the
     * order's current Pancake tags don't happen to include a trigger
     * keyword anymore — that's a human decision, not this sync's to undo.
     */
    public function test_a_tsas_own_logged_callback_is_never_auto_cleared(): void
    {
        $gemma    = TsaShift::where('tsa_key', 'Gemma')->first();
        $product  = Product::where('display_name', 'SINUXYL')->first();
        $original = now()->addHours(3);
        $lead = Lead::create([
            'pancake_order_id' => '9507', 'customer_name' => 'TSA Scheduled',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called',
            'disposition' => 'Call Back', 'callback_at' => $original,
        ]);

        $this->fakePancake([[
            'id' => 9507, 'bill_full_name' => 'TSA Scheduled', 'bill_phone_number' => '09171234573',
            'tags' => [['name' => 'CONFIRMED VIA CALL']],
            'items' => [['variation_info' => ['name' => 'Sinuxyl']]],
            'inserted_at' => now()->toIso8601String(),
        ]]);

        Artisan::call('pancake:sync-leads');

        $lead->refresh();
        $this->assertSame('Call Back', $lead->disposition);
        $this->assertEquals($original->timestamp, $lead->callback_at->timestamp);
    }
}
