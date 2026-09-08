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

    /** Reversed for callbacks specifically (explicit request, 2026-09-08: "i
     *  want to make it like it is visible to all of the TSA's the
     *  callbacks") — this sidebar badge must agree with the Callbacks page
     *  itself, which now shows every TSA's due callbacks to any TSA (see
     *  LeadControllerTest's matching test). assigned/overdue stay scoped to
     *  the viewer's own leads, unchanged. */
    public function test_a_tsa_sees_every_tsas_due_callbacks_not_just_their_own(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel  = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'n7', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);
        Lead::create(['pancake_order_id' => 'n8', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);

        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->getJson(route('calls.notifications.counts'));

        $response->assertOk();
        $response->assertJson(['callbacks' => 2]);
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
