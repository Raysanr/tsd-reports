<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelesalesDailyApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/external/v1/telesales/daily?from=2026-11-03&to=2026-11-04';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telesales_api.token' => 'secret-token']);
    }

    public function test_it_rejects_a_missing_or_wrong_token_and_is_off_without_one(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
        $this->getJson(self::URL, ['Authorization' => 'Bearer nope'])->assertStatus(401);

        config(['services.telesales_api.token' => null]);
        $this->getJson(self::URL, ['Authorization' => 'Bearer secret-token'])->assertStatus(503);
    }

    public function test_it_validates_the_dates(): void
    {
        $auth = ['Authorization' => 'Bearer secret-token'];
        $this->getJson('/api/external/v1/telesales/daily', $auth)->assertStatus(422);
        $this->getJson('/api/external/v1/telesales/daily?from=2026-11-05&to=2026-11-01', $auth)->assertStatus(422);
        $this->getJson('/api/external/v1/telesales/daily?from=2026-01-01&to=2026-12-31', $auth)->assertStatus(422);
    }

    public function test_it_returns_daily_leads_sales_statuses_and_per_tsa_figures(): void
    {
        $tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
        $order = fn (string $id, array $extra) => Order::create($extra + [
            'pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $tsa->tsa_key, 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($tsa->tsa_key)], 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => '2026-11-03 10:00:00', 'synced_at' => now(),
        ]);
        $order('lead-only', ['disposition' => 'NOT ANSWERING']);
        $order('upsell-delivered', ['is_upsell' => true, 'amount' => 1000, 'status_code' => 3, 'disposition' => 'UPSELL W/ CONFIRMATION']);
        $order('upsell-returned', ['is_upsell' => true, 'amount' => 400, 'status_code' => 5, 'disposition' => 'UPSELL W/ CONFIRMATION']);
        $order('upsell-canceled', ['is_upsell' => true, 'amount' => 250, 'status_code' => 6, 'disposition' => 'UPSELL W/ CONFIRMATION']);
        $order('other-team', ['team' => 'Some Partner', 'is_upsell' => true, 'amount' => 9999, 'status_code' => 3]);

        $json = $this->getJson(self::URL, ['Authorization' => 'Bearer secret-token'])->assertOk()->json();

        $this->assertCount(2, $json['days']);
        $day = $json['days'][0];
        $this->assertSame('2026-11-03', $day['date']);
        $this->assertSame(4, $day['leads']);
        $this->assertSame(3, $day['orders']);
        $this->assertEquals(1650, $day['grossSales']);
        $this->assertEquals(250, $day['cancelledSales']);
        $this->assertEquals(400, $day['returnedSales']);
        $this->assertEquals(1000, $day['deliveredSales']);
        $this->assertNull($day['leadsFromFsd']);
        $this->assertEqualsCanonicalizing(['Received', 'Returned', 'Canceled'], array_column($day['byStatus'], 'status'));
        $this->assertSame(1650.0, (float) array_sum(array_column($day['byStatus'], 'sales')));

        $this->assertCount(1, $day['agents']);
        $this->assertSame($tsa->tsa_key, $day['agents'][0]['tsa']);
        $this->assertSame(4, $day['agents'][0]['leads']);
        $this->assertEquals(1650, $day['agents'][0]['grossSales']);

        $this->assertSame(0, $json['days'][1]['leads']);
        $this->assertSame([], $json['days'][1]['agents']);
    }
}
