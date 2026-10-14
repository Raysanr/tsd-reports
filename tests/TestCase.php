<?php

namespace Tests;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** One real TikTok-tagged POS order worth $grossSales for this TSA/card/
     *  day — TikTok card Gross Sales/Number of Orders/Cancelled are
     *  automated from these since 2026-10-14 (TiktokUpsellTotals), so a
     *  test can no longer seed them by typing into ExpectedIncomeTiktokEntry. */
    protected function seedTiktokOrder(int $tsaId, string $cardKey, $date, float $grossSales): Order
    {
        $tsa = TsaShift::findOrFail($tsaId);
        $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
        $tag = $cardKey === 'natureva' ? 'TIKTOK UPSELL -NATUREVA' : 'TIKTOK UPSELL -SH NATURALS';

        return Order::create([
            'pancake_order_id' => 'tiktok-' . uniqid(),
            'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key, 'product' => 'TikTok',
            'is_upsell' => true, 'amount' => $grossSales, 'status_code' => 2,
            'raw_tags' => [strtoupper((string) $tsa->tsa_key), 'SEPARATE PARCEL', $tag],
            'pancake_created_at' => $dateStr . ' 12:00:00', 'synced_at' => now(),
        ]);
    }
}
