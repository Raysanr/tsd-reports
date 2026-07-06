<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceAllProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_view_renders_one_row_per_product_with_correct_totals(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $today = now()->toDateString();

        // 1 confirmed-via-call order for SINUXYL
        Order::create([
            'pancake_order_id'   => 'sinuxyl-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Sinuxyl',
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        // 1 genuinely uncatered order for SINUXYL
        Order::create([
            'pancake_order_id'   => 'sinuxyl-2',
            'team'               => 'SH Naturals',
            'tsa_name'           => null,
            'disposition'        => 'UNCATERED LEADS',
            'product'            => 'Sinuxyl',
            'is_upsell'          => false,
            'status_code'        => 0,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => $today]));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) use ($sinuxyl) {
            $row = $rows->firstWhere('display_name', $sinuxyl->display_name);
            return $row
                && $row['total'] === 2
                && $row['catered'] === 1
                && $row['excess'] === 1;
        });
        $response->assertViewHas('grandTotal', fn($g) => $g['total'] === 2 && $g['catered'] === 1 && $g['excess'] === 1);
    }

    public function test_all_view_does_not_expose_hourly_view_data(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewMissing('hourBlocks');
    }

    public function test_selecting_a_real_team_still_renders_the_hourly_view(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewIs('tsa-performance');
    }

    public function test_product_with_zero_orders_has_null_rates_not_a_division_error(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all', 'date' => '2020-01-01']));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->every(fn($r) => $r['total'] === 0 && $r['pick_up_rate'] === null && $r['conversion_rate'] === null && $r['upselling_rate'] === null);
        });
    }
}
