<?php

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

/**
 * Real production bug (2026-08-27), order #1358116, Katherine Chua: a
 * 2-item order tagged "UPSELL TSD - PTERYGIUM + LUMICARE + HAPLUNAS" — dash
 * format, no parens — named PTERYGIUM (₱800) as the base and the Lumicare +
 * Haplunas bundle (₱1,000) as the addon. Pancake's raw item array happened
 * to list the ₱1,000 bundle at index 0, so extractUpsellAmount()'s old
 * positional fallback (item 0 = base, sum everything after) recorded the
 * upsell as ₱800 instead of ₱1,000. Covers the fix (findBaseItemIndexByDashTag)
 * plus a regression check that the pre-existing positional fallback still
 * works when no tag names the base at all.
 */
class OrderExtractUpsellAmountTest extends TestCase
{
    public function test_dash_tag_with_addon_listed_first_in_raw_items_still_finds_the_named_base(): void
    {
        $raw = [
            'tags' => [
                ['id' => 1, 'name' => 'KATHERINE'],
                ['id' => 2, 'name' => 'PTERYGIUM'],
                ['id' => 3, 'name' => 'UPSELL TSD - PTERYGIUM + LUMICARE + HAPLUNAS'],
            ],
            'items' => [
                // Addon bundle listed FIRST (index 0) — the scenario the old
                // positional fallback got backwards.
                ['variation_info' => ['name' => 'LUMICARE OIL', 'retail_price' => 1000], 'quantity' => 1],
                ['variation_info' => ['name' => 'Pterygium', 'retail_price' => 800], 'quantity' => 1],
            ],
        ];

        // Base (PTERYGIUM) is actually at index 1 here — the whole point of
        // this test is that array position must NOT decide it.
        $this->assertSame(1, Order::findBaseItemIndexByDashTag($raw));
        $this->assertSame(1000.0, Order::extractUpsellAmount($raw));
    }

    public function test_dash_tag_with_base_listed_first_still_works(): void
    {
        $raw = [
            'tags' => [
                ['id' => 1, 'name' => 'UPSELL TSD - Sinuxyl + Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 500], 'quantity' => 1],
            ],
        ];

        $this->assertSame(0, Order::findBaseItemIndexByDashTag($raw));
        $this->assertSame(500.0, Order::extractUpsellAmount($raw));
    }

    /** No "Base + Addon" dash tag (single-name dash tag doesn't count — see
     *  findBaseItemIndexByDashTag()'s own docblock) and no parens hint either
     *  — extractUpsellAmount() must still fall back to raw array position. */
    public function test_falls_back_to_positional_assumption_when_no_tag_names_the_base(): void
    {
        $raw = [
            'tags' => [
                ['id' => 1, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 500], 'quantity' => 1],
            ],
        ];

        $this->assertNull(Order::findBaseItemIndexByDashTag($raw));
        $this->assertSame(500.0, Order::extractUpsellAmount($raw));
    }

    /** Parens hint still takes priority over the new dash-tag base hint when
     *  both are somehow present — no change to that existing precedence. */
    public function test_parens_hint_still_takes_priority_over_dash_base_hint(): void
    {
        $raw = [
            'tags' => [
                ['id' => 1, 'name' => 'UPSELL TSD (Ear Relief Balm)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 500], 'quantity' => 1],
            ],
        ];

        $this->assertSame(500.0, Order::extractUpsellAmount($raw));
    }
}
