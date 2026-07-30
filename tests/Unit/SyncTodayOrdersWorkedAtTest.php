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

    // A raw disposition tag from Pancake (unlike a tsaMap key) isn't pre-normalized —
    // e.g. "Not answering " with mixed case and a trailing space, exactly as it
    // appears on a real order. Must still match DFR's uppercase/trimmed history entry.
    public function test_matches_a_disposition_tag_regardless_of_case_or_whitespace(): void
    {
        $insertedAt = Carbon::parse('2026-07-04 06:13:32', 'Asia/Manila');

        $workedAt = SyncTodayOrders::resolveWorkedAt($this->fixture(), '  dfr ', $insertedAt);

        $this->assertSame('2026-07-04 08:19:16', $workedAt->format('Y-m-d H:i:s'));
    }

    /**
     * Real payload shape for Pancake order #1339220 (SH Naturals/Sinuxyl,
     * 2026-07-30): GEMMA, SINUXYL, and the generic "Call in Progress (Sinuxyl
     * Inhaler)" tag were all added in ONE automated batch at 2026-07-29T23:46:46
     * UTC (07:46:46 Manila) — before Gemma had done anything. Her real work only
     * started at 2026-07-30T00:40:27 UTC (08:40:27 Manila), when the order's
     * status changed from New (0) to Purchasing (20); the "UPSELL TSD" tag
     * followed 11 seconds later. Fix #16 (prefer the disposition tag's own
     * add-time) resolves to the SAME 07:46:46 timestamp here, since the
     * disposition ("Call in Progress...") was bundled into that same batch — so
     * this needs the separate status-change override (Fix #17).
     */
    private function genericCallInProgressFixture(): array
    {
        return [
            'histories' => [
                [
                    'tags' => [
                        'old' => [],
                        'new' => [
                            ['id' => 1, 'name' => 'GEMMA'],
                            ['id' => 2, 'name' => 'SINUXYL'],
                            ['id' => 3, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                        ],
                    ],
                    'updated_at' => '2026-07-29T23:46:46',
                ],
                [
                    'status'     => ['old' => 0, 'new' => 20],
                    'updated_at' => '2026-07-30T00:40:27',
                ],
                [
                    'tags' => [
                        'old' => [['id' => 1, 'name' => 'GEMMA'], ['id' => 2, 'name' => 'SINUXYL'], ['id' => 3, 'name' => 'Call in Progress (Sinuxyl Inhaler)']],
                        'new' => [['id' => 1, 'name' => 'GEMMA'], ['id' => 2, 'name' => 'SINUXYL'], ['id' => 3, 'name' => 'Call in Progress (Sinuxyl Inhaler)'], ['id' => 4, 'name' => 'UPSELL TSD - Sinuxyl Inhaler']],
                    ],
                    'updated_at' => '2026-07-30T00:40:38',
                ],
            ],
        ];
    }

    public function test_prefers_a_later_status_change_over_the_generic_call_in_progress_tags_own_timestamp(): void
    {
        $insertedAt = Carbon::parse('2026-07-30 00:00:49', 'Asia/Manila');

        $workedAt = SyncTodayOrders::resolveWorkedAt(
            $this->genericCallInProgressFixture(),
            'Call in Progress (Sinuxyl Inhaler)',
            $insertedAt
        );

        $this->assertSame('2026-07-30 08:40:27', $workedAt->format('Y-m-d H:i:s'));
    }

    public function test_still_anchors_to_the_tags_own_timestamp_when_no_later_status_change_exists(): void
    {
        $insertedAt = Carbon::parse('2026-07-30 00:00:49', 'Asia/Manila');

        $fixture = $this->genericCallInProgressFixture();
        unset($fixture['histories'][1]); // no status-change entry — order genuinely still in progress

        $workedAt = SyncTodayOrders::resolveWorkedAt($fixture, 'Call in Progress (Sinuxyl Inhaler)', $insertedAt);

        $this->assertSame('2026-07-30 07:46:46', $workedAt->format('Y-m-d H:i:s'));
    }

    public function test_a_specific_outcome_tag_is_unaffected_by_a_later_status_change(): void
    {
        // A later status change should only override the GENERIC "Call in
        // Progress" catch-all — a specific outcome tag (e.g. DFR/CONFIRMED VIA
        // CALL) already reflects a real, dispositioned call and must keep its
        // own timestamp regardless of what happens to the order afterward.
        $insertedAt = Carbon::parse('2026-07-04 06:13:32', 'Asia/Manila');

        $fixture = $this->fixture();
        $fixture['histories'][] = [
            'status'     => ['old' => 0, 'new' => 20],
            'updated_at' => '2026-07-04T02:00:00', // 10:00 AM Manila — after the DFR tag
        ];

        $workedAt = SyncTodayOrders::resolveWorkedAt($fixture, 'DFR', $insertedAt);

        $this->assertSame('2026-07-04 08:19:16', $workedAt->format('Y-m-d H:i:s'));
    }
}
