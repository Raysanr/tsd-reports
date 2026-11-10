<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // Coverage gap found while extending dark mode to the report pages (Part 2a):
    // rts-report.blade.php had zero Feature test coverage before this. Just
    // proves the page renders for an authenticated user; not a styling-specific
    // test.
    public function test_index_renders_successfully_for_an_authenticated_user(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertViewIs('rts-report');
    }

    // UI/UX review finding: a range with genuinely no RTS/Delivered activity
    // showed a wall of ₱0.00 across every TSA in both teams with nothing
    // distinguishing "nothing happened" from "something's wrong."
    public function test_shows_empty_state_when_no_rts_or_delivered_activity(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertSee('No RTS, Delivered or Shipped upsells for', false);
        $response->assertDontSee('data-sortable-table', false);
    }

    public function test_shows_tables_instead_of_empty_state_when_delivered_activity_exists(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => 'rts-empty-state-test-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'CANPRO',
            'raw_tags'           => ['CANPRO', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => true,
            'status_code'        => 3,
            'amount'             => 499,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertDontSee('No RTS, Delivered or Shipped upsells for', false);
        $response->assertSee('data-sortable-table', false);
    }

    /** 2026-10-21 "make it all accurate": RTS / Delivered uses the same
     *  rules as Actual Income — RTS = Returning/Partial return/Returned,
     *  Delivered = Received + Collected money, every order of the team's
     *  TSAs even when the order's own team label differs. */
    public function test_rts_and_delivered_follow_the_actual_income_rules(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $date = today()->toDateString();
        $make = fn (string $id, int $status, float $amount, string $team = 'SH Naturals') => Order::create([
            'pancake_order_id' => $id, 'team' => $team, 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
            'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => $status, 'amount' => $amount,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        $make('rts-received', 3, 1000);
        $make('rts-collected', 16, 500);                     // Collected money = delivered
        $make('rts-other-team-label', 3, 250, 'Eyecare Team'); // still this TSA's sale
        $make('rts-returning', 4, 300);
        $make('rts-returned', 5, 200);
        $make('rts-shipped', 2, 700);                       // in transit: neither

        $row = $this->get(route('rts-report', ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->viewData('teamTables')->flatMap(fn ($t) => $t['rows'])->firstWhere('display_name', $shift->display_name);

        $this->assertEqualsWithDelta(1750, $row['delivered_amount'], 0.001);
        $this->assertEqualsWithDelta(500, $row['rts_amount'], 0.001);
        $this->assertSame(1, $row['shipped_qty']);
        $this->assertEqualsWithDelta(700, $row['shipped_amount'], 0.001);
        $split = \App\Support\ActualSplit::forTsaKeys([$shift->tsa_key], $date, $date);
        $this->assertEqualsWithDelta($split['delivered'], $row['delivered_amount'], 0.001);
        $this->assertEqualsWithDelta($split['returns'], $row['rts_amount'], 0.001);
    }

    /** Click an RTS/Delivered amount → the order IDs behind it. */
    public function test_drilldown_lists_the_orders_behind_rts_and_delivered(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $date = today()->toDateString();
        $make = fn (string $id, int $status, float $amount) => Order::create([
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
            'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => $status, 'amount' => $amount,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        $make('dd-received', 3, 1000);
        $make('dd-collected', 16, 500);
        $make('dd-returned', 5, 200);
        $make('dd-shipped', 2, 700);

        $ids = fn (array $q) => collect($this->getJson(route('rts-report.drilldown', $q + ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->json())->pluck('id')->sort()->values()->all();

        $this->assertSame(['dd-collected', 'dd-received'], $ids(['tsa' => $shift->tsa_key, 'column' => 'delivered']));
        $this->assertSame(['dd-returned'], $ids(['tsa' => $shift->tsa_key, 'column' => 'rts']));
        $this->assertSame(['dd-returned'], $ids(['team' => 'SH Naturals', 'column' => 'rts']));
        $this->assertSame(['dd-shipped'], $ids(['tsa' => $shift->tsa_key, 'column' => 'shipped']));
        $this->assertSame(['dd-collected', 'dd-received'], $ids(['team' => 'all', 'column' => 'delivered']));
    }

    /** Shipped = status 2 only; qty + amount per TSA, team totals and Both Teams. */
    public function test_shipped_qty_and_amount_per_tsa_team_and_both_teams(): void
    {
        $sh = TsaShift::where('team', 'SH Naturals')->first();
        $other = TsaShift::where('team', '!=', 'SH Naturals')->first();
        $date = today()->toDateString();
        $make = fn (string $id, TsaShift $shift, int $status, float $amount) => Order::create([
            'pancake_order_id' => $id, 'team' => $shift->team, 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
            'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => $status, 'amount' => $amount,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        $make('sh-1', $sh, 2, 700);
        $make('sh-2', $sh, 2, 300);
        $make('sh-received', $sh, 3, 999);   // delivered, not shipped
        $make('sh-deleted', $sh, 7, 500);    // deleted never counts
        $make('ot-1', $other, 2, 400);

        $view = $this->get(route('rts-report', ['date_from' => $date, 'date_to' => $date]))->assertOk();
        $tables = $view->viewData('teamTables');
        $row = $tables->flatMap(fn ($t) => $t['rows'])->firstWhere('tsa_key', $sh->tsa_key);
        $shTable = $tables->firstWhere('team_key', 'SH Naturals');

        $this->assertSame(2, $row['shipped_qty']);
        $this->assertEqualsWithDelta(1000, $row['shipped_amount'], 0.001);
        $this->assertSame(2, $shTable['total_shipped_qty']);
        $this->assertEqualsWithDelta(1000, $shTable['total_shipped_amount'], 0.001);
        $this->assertSame(3, $view->viewData('grandShippedQty'));
        $this->assertEqualsWithDelta(1400, $view->viewData('grandShippedAmount'), 0.001);
        $view->assertSee('Total Shipped', false);
    }

    /** A range with only shipped orders shows the tables, not the empty state. */
    public function test_shipped_only_range_shows_tables(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id' => 'only-shipped', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
            'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => 2, 'amount' => 700,
            'pancake_created_at' => today()->toDateString() . ' 10:00:00', 'synced_at' => now(),
        ]);

        $this->get(route('rts-report'))->assertOk()
            ->assertDontSee('No RTS, Delivered or Shipped upsells for', false)
            ->assertSee('data-sortable-table', false);
    }

    /** Every clickable cell's pop-up lists exactly the orders behind it, so
     *  the listed amounts add up to the cell, at TSA, team and Both Teams level. */
    public function test_drilldown_amounts_sum_to_each_cell(): void
    {
        $sh = TsaShift::where('team', 'SH Naturals')->first();
        $other = TsaShift::where('team', '!=', 'SH Naturals')->first();
        $date = today()->toDateString();
        $make = fn (string $id, TsaShift $shift, int $status, float $amount) => Order::create([
            'pancake_order_id' => $id, 'team' => $shift->team, 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
            'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => $status, 'amount' => $amount,
            'pancake_created_at' => $date . ' 10:00:00', 'synced_at' => now(),
        ]);
        $make('a-rcv', $sh, 3, 1000);
        $make('a-ret', $sh, 5, 200);
        $make('a-shp', $sh, 2, 700);
        $make('a-can', $sh, 6, 900);         // cancelled: in no column
        $make('a-plain', $sh, 3, 800)->update(['raw_tags' => []]); // not an upsell
        $make('b-rcv', $other, 3, 400);
        $make('b-ret', $other, 4, 150);
        $make('b-shp', $other, 2, 50);

        $tables = $this->get(route('rts-report', ['date_from' => $date, 'date_to' => $date]))->viewData('teamTables');
        $row = $tables->flatMap(fn ($t) => $t['rows'])->firstWhere('tsa_key', $sh->tsa_key);
        $shTable = $tables->firstWhere('team_key', 'SH Naturals');

        $list = fn (array $q) => collect($this->getJson(route('rts-report.drilldown', $q + ['date_from' => $date, 'date_to' => $date]))->assertOk()->json());
        $total = fn (array $q) => $list($q)->sum(fn ($o) => (float) str_replace(',', '', \Illuminate\Support\Str::afterLast($o['status'], '₱')));

        foreach (['rts' => 'rts_amount', 'delivered' => 'delivered_amount', 'shipped' => 'shipped_amount'] as $column => $key) {
            $this->assertEqualsWithDelta($row[$key], $total(['tsa' => $sh->tsa_key, 'column' => $column]), 0.001, "TSA $column");
        }
        $this->assertEqualsWithDelta($shTable['total_rts'], $total(['team' => 'SH Naturals', 'column' => 'rts']), 0.001);
        $this->assertEqualsWithDelta($shTable['total_delivered'], $total(['team' => 'SH Naturals', 'column' => 'delivered']), 0.001);
        $this->assertEqualsWithDelta($shTable['total_shipped_amount'], $total(['team' => 'SH Naturals', 'column' => 'shipped']), 0.001);
        $this->assertSame($row['shipped_qty'], $list(['tsa' => $sh->tsa_key, 'column' => 'shipped'])->count());

        $this->assertSame(['a-ret'], $list(['team' => 'SH Naturals', 'column' => 'rts'])->pluck('id')->all());
        $this->assertSame(['a-ret', 'b-ret'], $list(['team' => 'all', 'column' => 'rts'])->pluck('id')->sort()->values()->all());
        $this->assertSame(['a-shp', 'b-shp'], $list(['team' => 'all', 'column' => 'shipped'])->pluck('id')->sort()->values()->all());
        $this->assertSame(['a-rcv'], $list(['tsa' => $sh->tsa_key, 'column' => 'delivered'])->pluck('id')->all());
    }

    /** Pop-up rows are newest first. */
    public function test_drilldown_lists_newest_first(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $date = today()->toDateString();
        foreach (['early' => '08:00:00', 'late' => '15:00:00', 'mid' => '11:00:00'] as $id => $time) {
            Order::create([
                'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key, 'product' => 'CANPRO',
                'is_upsell' => false, 'raw_tags' => ['UPSELL TSD - CANPRO'], 'status_code' => 3, 'amount' => 100,
                'pancake_created_at' => "$date $time", 'synced_at' => now(),
            ]);
        }

        $ids = $this->getJson(route('rts-report.drilldown', ['tsa' => $shift->tsa_key, 'column' => 'delivered', 'date_from' => $date]))
            ->assertOk()->json('*.id');

        $this->assertSame(['late', 'mid', 'early'], $ids);
    }
}
