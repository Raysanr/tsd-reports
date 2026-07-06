<?php

namespace Tests\Unit;

use App\Console\Commands\SyncTodayOrders;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SyncTodayOrdersWorkedAtTest extends TestCase
{
    /**
     * Real payload shape for Pancake order #1326271: inserted_at is 2026-07-03T22:13:32 UTC
     * (2026-07-04 06:13 Manila, an auto-created Facebook lead with no tags yet), but the
     * histories log shows MARISOL's tag wasn't added until 2026-07-04T00:19:16 UTC
     * (2026-07-04 08:19 Manila) — that's when she actually worked it.
     */
    private function fixture(): array
    {
        return [
            'histories' => [
                [
                    'tags' => [
                        'old' => [],
                        'new' => [
                            ['id' => 385, 'name' => 'PTERYGIUM'],
                            ['id' => 315, 'name' => 'MARISOL'],
                            ['id' => 269, 'name' => 'DFR'],
                        ],
                    ],
                    'updated_at' => '2026-07-04T00:19:16',
                ],
                [
                    'assigning_seller_id' => ['new' => 'x', 'old' => 'y'],
                    'updated_at' => '2026-07-04T05:12:14',
                ],
            ],
        ];
    }

    public function test_uses_history_timestamp_when_tsa_tag_was_added_after_creation(): void
    {
        $insertedAt = Carbon::parse('2026-07-04 06:13:32', 'Asia/Manila');

        $workedAt = SyncTodayOrders::resolveWorkedAt($this->fixture(), 'MARISOL', $insertedAt);

        $this->assertSame('2026-07-04 08:19:16', $workedAt->format('Y-m-d H:i:s'));
    }

    public function test_falls_back_to_inserted_at_when_no_matched_tag(): void
    {
        $insertedAt = Carbon::parse('2026-07-04 06:13:32', 'Asia/Manila');

        $workedAt = SyncTodayOrders::resolveWorkedAt($this->fixture(), null, $insertedAt);

        $this->assertTrue($insertedAt->equalTo($workedAt));
    }

    public function test_falls_back_to_inserted_at_when_tag_was_never_added_via_history(): void
    {
        $insertedAt = Carbon::parse('2026-07-04 06:13:32', 'Asia/Manila');

        $workedAt = SyncTodayOrders::resolveWorkedAt($this->fixture(), 'JULIE', $insertedAt);

        $this->assertTrue($insertedAt->equalTo($workedAt));
    }
}
