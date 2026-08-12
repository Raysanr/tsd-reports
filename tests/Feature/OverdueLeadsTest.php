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
class OverdueLeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_assigned_past_the_threshold_and_still_uncalled_shows_up_as_overdue(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $old = Lead::create([
            'pancake_order_id' => 'old1', 'customer_name' => 'Old Lead', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5),
        ]);
        $recent = Lead::create([
            'pancake_order_id' => 'recent1', 'customer_name' => 'Recent Lead', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(1),
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'overdue']));

        $response->assertOk();
        $response->assertSee('Old Lead');
        $response->assertDontSee('Recent Lead');
    }

    public function test_a_called_lead_never_shows_up_as_overdue_even_if_old(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => 'called1', 'customer_name' => 'Already Called', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'assigned_at' => now()->subHours(10), 'called_at' => now(),
        ]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'overdue']));

        $response->assertDontSee('Already Called');
    }

    public function test_a_tsa_only_sees_their_own_overdue_leads(): void
    {
        Setting::set('overdue_threshold_hours', 4);
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => 'g1', 'customer_name' => 'Gemma Overdue', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        Lead::create(['pancake_order_id' => 'm1', 'customer_name' => 'Mariel Overdue', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(5)]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'overdue']));

        $response->assertSee('Gemma Overdue');
        $response->assertDontSee('Mariel Overdue');
    }
}
