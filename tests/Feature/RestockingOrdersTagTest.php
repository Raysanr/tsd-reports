<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\ProductPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Leads Report / Dashboard "Restocking Orders" = orders tagged
 *  "RESTOCKING ORDERS" in Pancake, not orders in Restocking status
 *  (explicit request, 2026-10-23). */
class RestockingOrdersTagTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $id, array $tags, int $status = 1, ?string $disposition = null): Order
    {
        return Order::create([
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => 'Gemma', 'product' => 'CANPRO',
            'raw_tags' => $tags, 'status_code' => $status, 'disposition' => $disposition, 'is_upsell' => false,
            'amount' => 500, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
    }

    public function test_restocking_counts_the_tag_not_the_status(): void
    {
        $this->order('tag-1', ['RESTOCKING ORDERS']);
        $this->order('tag-2', ['restocking orders ']);              // any case / spacing
        $this->order('status-only', ['CANPRO'], 11);                // Restocking status, no tag
        $this->order('similar-tag', ['RMS - Duplicate Restocking Record']);
        $this->order('plain', ['CANPRO']);

        $orders = Order::all();
        $row = ProductPerformance::tally($orders);
        $listed = ProductPerformance::ordersForColumn($orders, 'restocking')->pluck('pancake_order_id')->sort()->values()->all();

        $this->assertSame(2, $row['restocking']);
        $this->assertSame(['tag-1', 'tag-2'], $listed);   // the pop-up lists exactly what's counted
        $this->assertSame($row['total'] - $row['catered'] - $row['restocking'], $row['excess']);
    }
}
