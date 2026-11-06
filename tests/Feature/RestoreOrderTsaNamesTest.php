<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreOrderTsaNamesTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $id, array $tags, ?string $name = null): Order
    {
        return Order::create(['pancake_order_id' => $id, 'team' => 'SH Naturals', 'tsa_name' => $name, 'raw_tags' => $tags, 'status_code' => 2, 'pancake_created_at' => '2026-10-15 11:00:00', 'synced_at' => now()]);
    }

    private function nameOf(string $id): ?string
    {
        return Order::where('pancake_order_id', $id)->value('tsa_name');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->order('blank', ['GEMMA']);

        $this->artisan('orders:restore-tsa-names')->assertSuccessful();

        $this->assertNull($this->nameOf('blank'));
    }

    public function test_apply_fills_only_blank_orders_with_one_tsa_on_their_tags(): void
    {
        TsaShift::where('tsa_key', 'Gemma')->firstOrFail()->delete();
        $this->order('blank', ['GEMMA', 'Unreconciled']);
        $this->order('named', ['GEMMA'], 'Joana');
        $this->order('nobody', ['UNCATERED LEADS']);
        $this->order('two', ['GEMMA', 'JOANA']);

        $this->artisan('orders:restore-tsa-names', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Gemma', $this->nameOf('blank'));
        $this->assertSame('Joana', $this->nameOf('named'));
        $this->assertNull($this->nameOf('nobody'));
        $this->assertNull($this->nameOf('two'));
    }

    public function test_a_tsa_removed_before_the_order_is_not_restored(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        $gemma->delete();
        $gemma->forceFill(['deleted_at' => '2026-10-01 10:00:00'])->saveQuietly();
        $this->order('blank', ['GEMMA']);

        $this->artisan('orders:restore-tsa-names', ['--apply' => true])->assertSuccessful();

        $this->assertNull($this->nameOf('blank'));
    }
}
