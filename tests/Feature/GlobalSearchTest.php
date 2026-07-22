<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_search_returns_matching_tsa_with_correct_individual_performance_url(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();
        // Marisol/Eyecare Team is seeded by the tsa_shifts migrations — use whichever
        // seeded TSA is actually present rather than assuming a specific name.
        $this->assertNotNull($shift, 'Expected at least one seeded Eyecare Team TSA shift.');

        $response = $this->getJson('/search?q=' . urlencode(substr($shift->display_name, 0, 3)));

        $response->assertOk();
        $response->assertJsonFragment([
            'label' => $shift->display_name,
            'url'   => route('tsa-performance.individual', ['team' => 'eyecare', 'tsaKey' => $shift->tsa_key]),
        ]);
    }

    public function test_search_returns_matching_visible_product_with_correct_filtered_url(): void
    {
        $product = Product::create([
            'display_name' => 'SearchableWidget',
            'team'          => 'SH Naturals',
            'sort_order'    => 1,
            'is_hidden'     => false,
        ]);

        $response = $this->getJson('/search?q=SearchableWidget');

        $response->assertOk();
        $response->assertJsonFragment([
            'label' => 'SearchableWidget',
            'url'   => route('tsa-performance', ['team' => 'sh-naturals', 'product' => 'SearchableWidget']),
        ]);
    }

    public function test_hidden_products_are_excluded_from_search(): void
    {
        // is_hidden isn't mass-assignable (see Product::$fillable) — it's only ever
        // flipped via ProductManagementController::toggleHidden, which sets the
        // attribute directly and saves. Mirror that here rather than passing it to
        // create(), which would silently be ignored and leave the default (false).
        $product = Product::create([
            'display_name' => 'HiddenWidget',
            'team'          => 'SH Naturals',
            'sort_order'    => 1,
        ]);
        $product->is_hidden = true;
        $product->save();

        $response = $this->getJson('/search?q=HiddenWidget');

        $response->assertOk();
        $response->assertJsonPath('products', []);
    }

    public function test_query_shorter_than_two_characters_returns_empty_results(): void
    {
        $response = $this->getJson('/search?q=a');

        $response->assertOk();
        $response->assertJson(['tsas' => [], 'products' => []]);
    }

    public function test_no_matches_returns_empty_arrays_not_an_error(): void
    {
        $response = $this->getJson('/search?q=zzzznonexistentzzzz');

        $response->assertOk();
        $response->assertJson(['tsas' => [], 'products' => []]);
    }

    public function test_normal_role_user_can_use_search(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->getJson('/search?q=xyz');

        $response->assertOk();
    }

    public function test_product_with_team_not_in_config_is_silently_dropped(): void
    {
        // 'team' on Product/TsaShift stores the literal order_team string, which is
        // reverse-looked-up against config('teams') to find the URL slug. A value
        // that doesn't match any configured team (messy data, a since-renamed team,
        // etc.) has no slug to build a route() call with — SearchController drops
        // it via ->filter() rather than crashing. Prove that here: the match exists
        // but the request still succeeds and the mismatched product never appears.
        Product::create([
            'display_name' => 'OrphanTeamWidget',
            'team'          => 'Nonexistent Team',
            'sort_order'    => 1,
        ]);

        $response = $this->getJson('/search?q=OrphanTeamWidget');

        $response->assertOk();
        $response->assertJsonPath('products', []);
    }

    public function test_results_are_capped_at_max_results_per_type(): void
    {
        // 6 matches for one query, only 5 (MAX_RESULTS_PER_TYPE) should come back.
        for ($i = 1; $i <= 6; $i++) {
            TsaShift::create([
                'tsa_key'      => "CapTest{$i}",
                'display_name' => "CapTestAgent {$i}",
                'team'         => 'SH Naturals',
                'sort_order'   => 100 + $i,
            ]);
        }

        $response = $this->getJson('/search?q=CapTestAgent');

        $response->assertOk();
        $this->assertCount(5, $response->json('tsas'));
    }

    public function test_search_returns_matching_order_linking_to_leads_report_for_its_team_and_date(): void
    {
        Order::create([
            'pancake_order_id'    => 'SearchableOrder1',
            'team'                => 'SH Naturals',
            'product'             => 'Sinuxyl',
            'disposition'         => 'CONFIRMED VIA CALL',
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ]);

        $response = $this->getJson('/search?q=SearchableOrder1');

        $response->assertOk();
        $response->assertJsonFragment([
            'label' => '#SearchableOrder1 — Sinuxyl',
            'url'   => route('leads-report', [
                'team' => 'sh-naturals', 'range' => 'dates',
                'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
            ]),
        ]);
    }

    public function test_unmatched_order_with_no_team_is_excluded_from_search(): void
    {
        // team NULL (Unmatched Orders) has nowhere sensible to link to — dropped
        // entirely rather than linking somewhere wrong, same convention as a
        // product/TSA whose team doesn't match config('teams').
        Order::create([
            'pancake_order_id'    => 'UnmatchedOrder1',
            'team'                => null,
            'product'             => 'SomeUntrackedProduct',
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_created_at'  => now(),
            'pancake_inserted_at' => now(),
            'synced_at'           => now(),
        ]);

        $response = $this->getJson('/search?q=UnmatchedOrder1');

        $response->assertOk();
        $response->assertJsonPath('orders', []);
    }

    public function test_users_and_audit_log_are_empty_for_a_normal_role_user(): void
    {
        $this->actingAs(User::factory()->normal()->create(['name' => 'SearchableStaffMember']));
        ActivityLog::create(['action' => 'test.action', 'description' => 'SearchableAuditEntry happened']);

        $response = $this->getJson('/search?q=Searchable');

        $response->assertOk();
        $response->assertJsonPath('users', []);
        $response->assertJsonPath('auditLog', []);
    }

    public function test_users_and_audit_log_are_populated_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $staff = User::factory()->create(['name' => 'SearchableStaffMember', 'email' => 'searchablestaff@example.com']);
        ActivityLog::create(['action' => 'test.action', 'description' => 'SearchableAuditEntry happened']);

        $response = $this->getJson('/search?q=Searchable');

        $response->assertOk();
        $response->assertJsonFragment([
            'label' => "{$staff->name} ({$staff->email})",
            'url'   => route('user-management'),
        ]);
        $response->assertJsonFragment([
            'label' => 'SearchableAuditEntry happened',
            'url'   => route('audit-log', ['q' => 'Searchable']),
        ]);
    }
}
