<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PancakeReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEmptyTagCatalog(): void
    {
        // Tag-drift check isn't under test here — every TSA needs at least one
        // matching keyword (not necessarily every keyword — see checkTagDrift's
        // per-TSA "any match" semantics) to stay silent in these tests. Deliberately
        // omits "KATHLEEN" (only "KATH" is here) to mirror real Pancake data, where
        // Kathleen's real tag is the abbreviation, not her full first name — that
        // must NOT raise an issue since her "KATH" keyword still matches.
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    ['id' => 4, 'name' => 'JULIE'],
                    ['id' => 5, 'name' => 'JOANA'],
                    ['id' => 6, 'name' => 'MARISOL'],
                ],
            ], 200),
        ]);
    }

    public function test_flags_a_day_where_pancake_reports_far_more_orders_than_are_synced_locally(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday = Carbon::now('Asia/Manila')->subDay();

        // Only 2 orders synced locally for yesterday...
        Order::factory()->count(2)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            // ...but Pancake reports 50 for the same window — the outage scenario.
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Completeness', $issues[0]);
        $this->assertStringContainsString((string) $yesterday->toDateString(), $issues[0]);
        $this->assertNotEmpty(Setting::get('reconciliation_last_run'));
    }

    public function test_does_not_flag_a_day_where_local_count_is_close_to_pancakes(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday = Carbon::now('Asia/Manila')->subDay();

        Order::factory()->count(48)->create([
            'pancake_created_at' => $yesterday->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues);
    }

    public function test_flags_a_configured_tsa_keyword_that_matches_no_real_pancake_tag(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        // Simulate a typo: someone changed Julie's keyword in TSA Management.
        \App\Models\TsaShift::where('tsa_key', 'Julie')->update(['tag_keywords' => 'JULEE']);

        Http::fake([
            // No completeness gap — matches "yesterday" order count exactly.
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 0, 'total_pages' => 0,
            ], 200),
            // Covers at least one keyword per other TSA, but deliberately omits
            // anything matching 'JULEE' — and 'JULIE' too, since Julie's row no
            // longer has that keyword after the update() above — so the drift is
            // genuinely detected (her only keyword now matches nothing at all).
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    ['id' => 4, 'name' => 'JOANA'],
                    ['id' => 5, 'name' => 'MARISOL'],
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $matching = array_filter($issues, fn($i) => str_contains($i, 'JULEE'));
        $this->assertNotEmpty($matching, 'Expected an issue mentioning the stale JULEE keyword');
    }

    public function test_does_not_flag_tsa_keywords_that_match_a_real_tag(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $this->fakeEmptyTagCatalog();
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 0, 'total_pages' => 0,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues, 'No TSA keyword should be flagged when every one matches a real tag');
    }

    public function test_completeness_check_follows_pancake_updated_at_not_pancake_created_at(): void
    {
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        $yesterday  = Carbon::now('Asia/Manila')->subDay();
        $twoDaysAgo = $yesterday->copy()->subDay();

        // A backlog-lead-like order: worked (pancake_created_at) two days ago, but
        // Pancake's own updated_at (pancake_updated_at) says it was touched
        // yesterday. Before this fix, the completeness check read
        // pancake_created_at and would NOT have counted this order toward
        // "yesterday" at all — after the fix, it must.
        Order::factory()->count(50)->create([
            'pancake_created_at' => $twoDaysAgo->copy()->setTime(10, 0),
            'pancake_updated_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        $this->fakeEmptyTagCatalog();
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [], 'total_entries' => 50, 'total_pages' => 50,
            ], 200),
        ]);

        Artisan::call('pancake:reconcile');

        $issues = json_decode(Setting::get('reconciliation_issues'), true);
        $this->assertSame([], $issues, 'Orders whose pancake_updated_at falls yesterday should count toward yesterday, even if pancake_created_at does not');
    }
}
