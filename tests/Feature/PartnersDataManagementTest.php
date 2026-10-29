<?php

namespace Tests\Feature;

use App\Models\CostBreakdownRole;
use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Services\PartnerSync;
use App\Support\DataSet;
use App\Support\TsaDailyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Partners Data Management (2026-10-29): TSD's Data Management on synced partner data. */
class PartnersDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['partners.secret' => 'shh']);
        $this->artisan('migrate', ['--database' => DataSet::PARTNERS]);
    }

    protected function tearDown(): void
    {
        DataSet::reset();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Raysan']);
    }

    /** Fakes PEAK.CO's feed and syncs it; the other two partners send nothing. */
    private function syncPeak(array $laterOrders = []): void
    {
        $date = '2026-10-05';
        $order = fn (int $id, string $status, float $amount) => [
            'id' => $id, 'pancake_order_id' => (string) (900 + $id), 'team' => 'Opening', 'tsa_name' => 'JOANA', 'product' => 'PEAKGLOW',
            'is_upsell' => 0, 'amount' => $amount, 'status_code' => $status, 'raw_tags' => json_encode(['UPSELL TSD - PEAKGLOW']),
            'pancake_created_at' => "{$date} 10:00:00", 'synced_at' => "{$date} 10:05:00", 'created_at' => "{$date} 10:05:00", 'updated_at' => "{$date} 10:05:0{$id}",
        ];
        Http::fake([
            'peak-co*/api/tsd-feed/products' => Http::response(['rows' => [
                ['id' => 7, 'display_name' => 'PEAKGLOW', 'match_keyword' => 'PEAKGLOW', 'team' => 'Opening', 'sort_order' => 1, 'is_hidden' => 0, 'has_cost_allocation' => 1, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00', 'deleted_at' => null],
            ]]),
            'peak-co*/api/tsd-feed/tsas' => Http::response(['rows' => [
                ['id' => 3, 'tsa_key' => 'JOANA', 'display_name' => 'Joana', 'team' => 'Opening', 'tiktok_upsell' => 1, 'active' => 1, 'sort_order' => 1, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00', 'deleted_at' => null],
            ]]),
            'peak-co*/api/tsd-feed/orders*' => Http::sequence()->push(['rows' => [$order(1, 3, 1000), $order(2, 1, 500)]])
                ->push(['rows' => $laterOrders])->whenEmpty(Http::response(['rows' => []])),
        ]);
        app(PartnerSync::class)->sync('peak-co');
        if ($laterOrders !== []) {
            app(PartnerSync::class)->sync('peak-co');
        }
    }

    public function test_sync_copies_partner_data_tagged_with_the_partner(): void
    {
        $this->syncPeak();

        DataSet::onPartners(function () {
            $this->assertSame(['PEAKGLOW'], Product::pluck('display_name')->all(), 'TSD products seeded by migrations are removed');
            $this->assertSame('PEAK.CO', Product::first()->team);
            $tsa = TsaShift::sole();
            $this->assertSame(['PEAK.CO:JOANA', 'Joana', 'PEAK.CO', false], [$tsa->tsa_key, $tsa->display_name, $tsa->team, (bool) $tsa->tiktok_upsell]);
            $this->assertSame(['peak-co:901', 'peak-co:902'], DB::table('orders')->orderBy('pancake_order_id')->pluck('pancake_order_id')->all());
            $this->assertSame('PEAK.CO:JOANA', DB::table('orders')->value('tsa_name'));
        });

        $this->assertNotContains('PEAKGLOW', Product::pluck('display_name')->all(), 'main database untouched');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer shh'));
    }

    public function test_sync_command_clears_tsd_seed_rows_even_without_a_secret(): void
    {
        config(['partners.secret' => '']);
        $this->assertGreaterThan(0, DataSet::onPartners(fn () => Product::count()));

        $this->artisan('partners:sync')->assertSuccessful();

        DataSet::onPartners(fn () => $this->assertSame([0, 0], [Product::count(), TsaShift::count()]));
        $this->assertGreaterThan(0, Product::count(), 'main database untouched');
    }

    public function test_sync_is_incremental_and_updates_changed_orders(): void
    {
        $this->syncPeak([
            ['id' => 1, 'pancake_order_id' => '901', 'team' => 'Opening', 'tsa_name' => 'JOANA', 'product' => 'PEAKGLOW', 'amount' => 1000, 'status_code' => 6,
             'pancake_created_at' => '2026-10-05 10:00:00', 'created_at' => '2026-10-05 10:05:00', 'updated_at' => '2026-10-06 08:00:00'],
        ]);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'orders') && ($r->data()['since'] ?? null) === '2026-10-05 10:05:02' && (int) ($r->data()['after_id'] ?? 0) === 2);
        DataSet::onPartners(fn () => $this->assertSame(6, (int) DB::table('orders')->where('pancake_order_id', 'peak-co:901')->value('status_code')));
    }

    public function test_tsas_cannot_open_partners_but_keep_tsd_data_management(): void
    {
        $tsa = User::factory()->create(['role' => 'normal']);
        $this->actingAs($tsa)->get('/partners/projections')->assertForbidden();
        $this->actingAs($tsa)->get('/data/projections')->assertOk()->assertSee('TSD Data Management');
    }

    public function test_partner_pages_run_tsd_data_management_on_partner_data(): void
    {
        $this->syncPeak();
        $this->actingAs($this->admin());

        $this->get('/partners/expected-income?date_from=2026-10-05&date_to=2026-10-05&team=peak-co')->assertOk()
            ->assertSee('Partners Data Management')->assertSee('PEAK.CO')->assertSee('M&amp;J', false)->assertSee('Joana')->assertSee('PEAKGLOW')
            ->assertDontSee('TIKTOK')->assertSee(url('/partners/expected-income'), false)->assertDontSee('href="' . url('/partners/dsppr') . '"', false);
        $this->get('/partners/actual-income?month=2026-10')->assertOk()->assertSee('PEAKGLOW');
        $this->get('/partners/tsa-sales?date_from=2026-10-05&date_to=2026-10-05')->assertOk()->assertSee('JOANA')
            ->assertDontSee('class="tsr-group-total-row tsr-tiktok-total-row', false)->assertDontSee('No TSAs in TikTok Upsell yet')
            ->assertSee(url('/partners/tsa-sales/lock'), false);
        $this->get('/partners/projections?month=2026-10')->assertOk()->assertSee('PEAK.CO: Monthly Target')->assertSee('MLC — Individual TSA: Daily Target');
        $this->get('/partners/cost-breakdown')->assertOk()->assertSee('Telesales Supervisor')->assertSee('Joana')->assertDontSee('Lhiza Alconera');

        // TSD's own pages still show TSD's data.
        $this->get('/data/expected-income?date_from=2026-10-05&date_to=2026-10-05')->assertOk()->assertSee('TSD Data Management')->assertDontSee('PEAKGLOW');
    }

    public function test_edits_save_to_the_partners_database(): void
    {
        $this->syncPeak();
        $product = DataSet::onPartners(fn () => Product::sole());

        $this->actingAs($this->admin())
            ->patchJson("/partners/expected-income/{$product->id}/2026-10-05", ['advertising_cost' => 250])->assertOk();
        DataSet::reset(); // what ResetDataSet does at the start of the next request

        DataSet::onPartners(fn () => $this->assertEquals(250, ExpectedIncomeEntry::where('product_id', $product->id)->value('advertising_cost')));
        $this->assertSame(0, ExpectedIncomeEntry::count(), 'nothing written to the main database');
        $this->assertStringStartsWith('[Partners] ', \App\Models\ActivityLog::latest('id')->value('description'));
    }

    public function test_one_supervisor_is_split_per_partner_then_per_tsa(): void
    {
        DataSet::onPartners(function () {
            foreach (['PEAK.CO' => 2, 'M&J' => 1, 'MLC' => 0] as $team => $count) {
                for ($i = 0; $i < $count; $i++) {
                    TsaShift::create(['tsa_key' => "{$team}:T{$i}", 'display_name' => "T{$i}", 'team' => $team, 'active' => true]);
                }
            }
            CostBreakdownRole::ensureSeeded('2026-10');
            $supervisor = CostBreakdownRole::where('month', '2026-10')->sole();
            $supervisor->update(['base_salary' => 30000]);

            $roles = CostBreakdownRole::where('month', '2026-10')->get();
            $refs = TsaDailyRateService::partnerOverheadRefsByTeam($roles, collect([$supervisor->id => 30000 / CostBreakdownRole::divisorCount('partner')]));

            $this->assertEqualsWithDelta(5000, $refs['PEAK.CO'][0], 0.001);
            $this->assertEqualsWithDelta(10000, $refs['M&J'][0], 0.001);
            $this->assertEqualsWithDelta(0, $refs['MLC'][0], 0.001);
        });
    }
}
