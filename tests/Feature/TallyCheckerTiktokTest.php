<?php

namespace Tests\Feature;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request (2026-10-15): Tally Checker's TikTok section. */
class TallyCheckerTiktokTest extends TestCase
{
    use RefreshDatabase;

    private function tiktok(string $from, string $to): array
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('data.tally-checker', ['date_from' => $from, 'date_to' => $to]))
            ->assertOk()->json('tiktok');
    }

    protected function setUp(): void
    {
        parent::setUp();
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
    }

    public function test_tallies_when_every_tagged_order_reaches_a_tiktok_card(): void
    {
        $tsa = TsaShift::first();
        $tsa->update(['tiktok_upsell' => true]);
        $date = today()->toDateString();
        $this->seedTiktokOrder($tsa->id, 'natureva', $date, 1000);
        $this->seedTiktokOrder($tsa->id, 'sh_naturals', $date, 500);

        $t = $this->tiktok($date, $date);

        $this->assertTrue($t['checked']);
        $this->assertTrue($t['grossSales']['tallies']);
        $this->assertEquals(1500, $t['grossSales']['shared']);
        $this->assertTrue($t['numberOfOrders']['tallies']);
        $this->assertSame([], $t['missing']);
    }

    public function test_lists_a_tagged_order_from_a_tsa_not_flagged_for_tiktok(): void
    {
        TsaShift::first()->update(['tiktok_upsell' => true]);
        $unflagged = TsaShift::orderBy('id')->skip(1)->first();
        $date = today()->toDateString();
        $this->seedTiktokOrder($unflagged->id, 'natureva', $date, 700);

        $t = $this->tiktok($date, $date);

        $this->assertFalse($t['grossSales']['tallies']);
        $this->assertEquals(700, $t['grossSales']['independent']);
        $this->assertCount(1, $t['missing']);
        $this->assertSame($unflagged->display_name, $t['missing'][0]['tsaName']);
        $this->assertStringContainsString('TSA Management', $t['missing'][0]['reason']);
    }

    public function test_days_before_automation_are_not_checked(): void
    {
        $this->assertFalse($this->tiktok('2026-09-20', '2026-09-30')['checked']);
    }
}
