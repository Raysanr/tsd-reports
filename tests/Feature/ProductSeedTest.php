<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_all_16_products_matching_current_config(): void
    {
        $this->assertSame(16, Product::count());
        $this->assertSame(10, Product::where('team', 'SH Naturals')->count());
        $this->assertSame(6, Product::where('team', 'Eyecare Team')->count());
    }

    public function test_canpro_keeps_its_short_match_keyword(): void
    {
        $product = Product::where('display_name', 'CANPRO JUICE DRINK')->first();

        $this->assertNotNull($product);
        $this->assertSame('CANPRO', $product->match_keyword);
        $this->assertSame('CANPRO', $product->effective_keyword);
    }

    public function test_product_with_no_override_falls_back_to_display_name_as_keyword(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        $this->assertNotNull($product);
        $this->assertNull($product->match_keyword);
        $this->assertSame('SINUXYL', $product->effective_keyword);
    }
}
