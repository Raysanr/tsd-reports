<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;
use App\Support\RoundRobinAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Bug fix 2026-10-25: Jhun (10:00 shift) logged in at 6:01 with the 6:00
 *  team — the backlog start was anchored to 10:00 and Rooster Medallion 2.0
 *  leads waited until 10:03. */
class RoundRobinHandoverAnchorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setUpRoster(): array
    {
        Carbon::setTestNow('2026-10-25 06:02:00');
        $product = Product::first();
        TsaShift::query()->update(['status' => 'logout']);
        $early = TsaShift::where('tsa_key', 'Gemma')->first();
        $onTime = TsaShift::where('tsa_key', 'Julie')->first();
        $early->update(['shift_start' => '10:00', 'active' => true]);
        $onTime->update(['shift_start' => '06:00', 'active' => true]);
        $product->tsas()->sync([$early->id, $onTime->id]);

        return [$product, $early, $onTime];
    }

    public function test_a_later_shift_logging_in_early_with_the_current_shift_does_not_delay_the_backlog(): void
    {
        [$product, $early, $onTime] = $this->setUpRoster();
        $early->update(['status' => 'ready_to_call']);
        $onTime->update(['status' => 'ready_to_call']);

        RoundRobinAssigner::trackRosterAvailability($product);

        $this->assertTrue(RoundRobinState::where('product_id', $product->id)->first()->roster_available_since->lte(now()));
        Carbon::setTestNow('2026-10-25 06:06:00');
        $this->assertTrue(RoundRobinAssigner::isPastHandoverBuffer($product));
    }

    public function test_an_anchor_already_set_to_a_later_shift_is_pulled_back_once_the_current_shift_is_on(): void
    {
        [$product, $early, $onTime] = $this->setUpRoster();
        $early->update(['status' => 'ready_to_call']);           // only the 10:00 TSA so far
        RoundRobinAssigner::trackRosterAvailability($product);
        $this->assertSame('10:00', RoundRobinState::where('product_id', $product->id)->first()->roster_available_since->format('H:i'));

        $onTime->update(['status' => 'ready_to_call']);          // the 6:00 shift comes online
        RoundRobinAssigner::trackRosterAvailability($product);
        $this->assertTrue(RoundRobinState::where('product_id', $product->id)->first()->roster_available_since->lte(now()));
    }

    public function test_an_early_login_for_the_next_shift_alone_still_waits_for_that_shift(): void
    {
        [$product, $early] = $this->setUpRoster();
        $early->update(['status' => 'ready_to_call']);
        RoundRobinAssigner::trackRosterAvailability($product);

        $this->assertFalse(RoundRobinAssigner::isPastHandoverBuffer($product));
    }
}
