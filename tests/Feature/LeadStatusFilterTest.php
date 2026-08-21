<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-21): bring back a way to filter the Leads list
 * by status (Unassigned/Assigned/Called) — the old filter here was removed
 * 2026-08-20 in favor of a status-CHANGE control that looks similar but does
 * something different (see leads/index.blade.php's own comment on that
 * control). Only applies to the bare Leads view — Overdue/Callbacks already
 * have their own implicit status meaning, so a second status filter there
 * would just be confusing.
 */
class LeadStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedThreeStatuses(): array
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        $unassigned = Lead::create([
            'pancake_order_id' => 'un-1', 'customer_name' => 'Unassigned Lead',
            'product_id' => $product->id, 'tsa_id' => null, 'status' => 'unassigned',
        ]);
        $assigned = Lead::create([
            'pancake_order_id' => 'as-1', 'customer_name' => 'Assigned Lead',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);
        $called = Lead::create([
            'pancake_order_id' => 'ca-1', 'customer_name' => 'Called Lead',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'called_at' => now(),
        ]);

        return compact('unassigned', 'assigned', 'called');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_no_status_param_shows_every_lead(): void
    {
        $this->seedThreeStatuses();

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Unassigned Lead');
        $response->assertSee('Assigned Lead');
        $response->assertSee('Called Lead');
    }

    public function test_status_unassigned_shows_only_unassigned_leads(): void
    {
        $this->seedThreeStatuses();

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'unassigned']));

        $response->assertOk();
        $response->assertSee('Unassigned Lead');
        $response->assertDontSee('Assigned Lead');
        $response->assertDontSee('Called Lead');
    }

    public function test_status_assigned_shows_only_assigned_leads(): void
    {
        $this->seedThreeStatuses();

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'assigned']));

        $response->assertOk();
        $response->assertDontSee('Unassigned Lead');
        $response->assertSee('Assigned Lead');
        $response->assertDontSee('Called Lead');
    }

    public function test_status_called_shows_only_called_leads(): void
    {
        $this->seedThreeStatuses();

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'called']));

        $response->assertOk();
        $response->assertDontSee('Unassigned Lead');
        $response->assertDontSee('Assigned Lead');
        $response->assertSee('Called Lead');
    }

    public function test_status_is_ignored_on_the_overdue_view(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create([
            'pancake_order_id' => 'overdue-1', 'customer_name' => 'Overdue Lead',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'assigned_at' => now()->subHours(5),
        ]);

        // status=called would exclude this (it's status=assigned) if it were
        // honored here — it must not be, Overdue always means status=assigned.
        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', [
            'view' => 'overdue', 'status' => 'called',
        ]));

        $response->assertOk();
        $response->assertSee('Overdue Lead');
    }

    public function test_an_invalid_status_value_is_ignored_not_erroring(): void
    {
        $this->seedThreeStatuses();

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'not-a-real-status']));

        $response->assertOk();
        $response->assertSee('Unassigned Lead');
        $response->assertSee('Assigned Lead');
        $response->assertSee('Called Lead');
    }
}
