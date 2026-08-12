<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class NotificationCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_only_gets_counts_scoped_to_their_own_leads(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n2', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n3', 'product_id' => null, 'status' => 'unassigned']);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['assigned' => 1, 'overdue' => 1, 'unassigned' => 0]);
    }

    public function test_an_admin_sees_counts_across_every_tsa_plus_unassigned(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n4', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n5', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'n6', 'product_id' => null, 'status' => 'unassigned']);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['assigned' => 2, 'overdue' => 2, 'unassigned' => 1]);
    }
}
