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
        // Tag-drift check isn't under test here — return every configured TSA
        // keyword as a real tag so it never contributes an issue in these tests.
        // Note: the pre-seeded tsa_shifts migration configures TWO keywords for
        // Kathleen ("KATH,KATHLEEN") and Joana ("JOANA,JOANNA") — both spellings
        // are listed explicitly here (rather than relying on one substring-matching
        // the other) since "JOANA"/"JOANNA" don't contain one another as substrings.
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/tags*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'GEMMA'],
                    ['id' => 2, 'name' => 'MARIEL'],
                    ['id' => 3, 'name' => 'KATH'],
                    ['id' => 4, 'name' => 'KATHLEEN'],
                    ['id' => 5, 'name' => 'JULIE'],
                    ['id' => 6, 'name' => 'JOANA'],
                    ['id' => 7, 'name' => 'JOANNA'],
                    ['id' => 8, 'name' => 'MARISOL'],
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
}
