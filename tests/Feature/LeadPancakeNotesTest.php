<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit request (2026-08-22): show and edit Pancake POS's own order
 * notes (Internal / For printing — the only two real note fields Pancake's
 * API has, confirmed against the OpenAPI spec: `note` and `note_print`
 * directly on the Order object) from the lead detail page, live — reads and
 * writes both go straight to Pancake (PancakeOrderTagApi::getNotes()/
 * updateNotes()), never through this app's own `orders` table, so an edit
 * made directly in POS is picked up on the next poll and a save here shows
 * up in POS immediately.
 */
class LeadPancakeNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
    }

    private function leadFor(TsaShift $tsa, string $orderId = '9001'): Lead
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => 'Juan', 'product_id' => $product->id,
            'tsa_id' => $tsa->id, 'status' => 'assigned',
        ]);
    }

    public function test_notes_returns_the_leads_real_pancake_note_fields(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'note' => 'Called twice, no answer.', 'note_print' => 'REPEAT ORDER',
            ]], 200),
        ]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.notes', $lead));

        $response->assertOk()->assertJson([
            'success' => true, 'note' => 'Called twice, no answer.', 'note_print' => 'REPEAT ORDER',
        ]);
    }

    public function test_a_tsa_cannot_read_notes_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.notes', $lead));

        $response->assertForbidden();
    }

    public function test_update_notes_writes_both_fields_back_to_the_real_order(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'note' => 'old internal', 'note_print' => 'old print',
            ]], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.notes.update', $lead), [
            'note' => 'Customer wants callback tomorrow.', 'note_print' => 'FRAGILE — HANDLE WITH CARE',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r['note'] === 'Customer wants callback tomorrow.'
            && $r['note_print'] === 'FRAGILE — HANDLE WITH CARE');
    }

    /** null (field omitted from the request) must leave that field alone in
     *  Pancake — only an explicit empty string actually clears one. */
    public function test_update_notes_leaves_an_omitted_field_untouched(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['success' => true, 'data' => [
                'id' => 9001, 'note' => 'keep me', 'note_print' => 'old print',
            ]], 200),
        ]);

        $this->actingAs($user)->postJson(route('calls.leads.notes.update', $lead), [
            'note_print' => 'NEW PRINT NOTE',
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['note'] === 'keep me' && $r['note_print'] === 'NEW PRINT NOTE');
    }

    public function test_a_tsa_cannot_update_notes_on_another_tsas_lead(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadFor($gemma);
        $user   = User::create(['name' => 'Mariel User', 'email' => 'mariel@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.notes.update', $lead), ['note' => 'sneaky']);

        $response->assertForbidden();
    }

    public function test_update_notes_returns_an_error_when_the_pancake_write_fails(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead  = $this->leadFor($gemma);
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/9001*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.notes.update', $lead), ['note' => 'x']);

        $response->assertStatus(500)->assertJson(['success' => false]);
    }
}
