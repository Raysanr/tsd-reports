<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_normal_role_user_cannot_access_keyword_diagnostics(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('keyword-diagnostics'))->assertForbidden();
    }

    public function test_detects_duplicate_tsa_tag_keywords_across_two_tsas(): void
    {
        TsaShift::create(['tsa_key' => 'test1', 'display_name' => 'Test One', 'team' => 'SH Naturals', 'tag_keywords' => 'DUPLICATETAG', 'sort_order' => 90]);
        TsaShift::create(['tsa_key' => 'test2', 'display_name' => 'Test Two', 'team' => 'SH Naturals', 'tag_keywords' => 'DUPLICATETAG', 'sort_order' => 91]);

        $response = $this->get(route('keyword-diagnostics'));

        $response->assertOk();
        $response->assertSee('DUPLICATETAG', false);
        $response->assertSee('Test One', false);
        $response->assertSee('Test Two', false);
    }

    public function test_detects_overlapping_product_keywords(): void
    {
        Product::create(['display_name' => 'Base Product', 'match_keyword' => 'PTERYGIUM', 'team' => 'SH Naturals', 'sort_order' => 90]);
        Product::create(['display_name' => 'Extended Product', 'match_keyword' => 'PTERYGIUMPLUS', 'team' => 'SH Naturals', 'sort_order' => 91]);

        $response = $this->get(route('keyword-diagnostics'));

        $response->assertOk();
        $response->assertSee('Base Product', false);
        $response->assertSee('Extended Product', false);
    }

    public function test_no_conflicts_shows_clean_state_with_only_seeded_non_conflicting_data(): void
    {
        // The tsa_shifts/products tables are seeded by migrations with real,
        // non-conflicting keywords (verified by hand against the seed data in
        // 2026_07_02_193400_create_tsa_shifts_table.php,
        // 2026_07_03_140000_add_keywords_to_tsa_shifts_table.php and
        // 2026_07_06_000000_create_products_table.php) — this proves the
        // checker doesn't cry wolf on legitimate existing data.
        $response = $this->get(route('keyword-diagnostics'));

        $response->assertOk();
        $response->assertSee('No conflicts found', false);
    }

    public function test_test_keyword_endpoint_finds_a_matching_product(): void
    {
        Product::create(['display_name' => 'Clear Sight 3.0', 'match_keyword' => 'CLEARSIGHT', 'team' => 'SH Naturals', 'sort_order' => 90]);

        $response = $this->getJson(route('keyword-diagnostics.test', ['sample' => 'Clear Sight 3.0 Bottle']));

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Clear Sight 3.0']);
    }

    public function test_test_keyword_endpoint_surfaces_multiple_matching_products_as_ambiguity(): void
    {
        Product::create(['display_name' => 'Base Product', 'match_keyword' => 'WIDGET', 'team' => 'SH Naturals', 'sort_order' => 90]);
        Product::create(['display_name' => 'Extended Product', 'match_keyword' => 'WIDGETPRO', 'team' => 'SH Naturals', 'sort_order' => 91]);

        $response = $this->getJson(route('keyword-diagnostics.test', ['sample' => 'WIDGETPRO']));

        $response->assertOk();
        $this->assertCount(2, $response->json('products'));
    }
}
