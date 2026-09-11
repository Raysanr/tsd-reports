<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakePageToken;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class LeadConversationTest extends TestCase
{
    use RefreshDatabase;

    private function leadWithConversation(array $overrides = []): Lead
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        return Lead::create(array_merge([
            'pancake_order_id' => 'c1', 'customer_name' => 'Conversation Test', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned',
            'pancake_page_id' => '123', 'pancake_conversation_id' => 'conv-1',
        ], $overrides));
    }

    public function test_a_tsa_can_fetch_their_own_leads_conversation(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response([
                'success' => true, 'messages' => [['id' => 'm1', 'message' => 'Hi']],
            ], 200),
        ]);

        $response = $this->actingAs($user)->getJson(route('calls.leads.conversation', $lead));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonCount(1, 'messages');
    }

    public function test_a_tsa_cannot_fetch_another_tsas_lead_conversation(): void
    {
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadWithConversation();
        $user   = User::factory()->create(['role' => 'tsa', 'tsa_id' => $mariel->id]);

        $this->actingAs($user)->getJson(route('calls.leads.conversation', $lead))->assertForbidden();
    }

    public function test_a_lead_with_no_page_or_conversation_id_returns_a_friendly_error_without_calling_pancake(): void
    {
        $lead = $this->leadWithConversation(['pancake_page_id' => null, 'pancake_conversation_id' => null]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);
        Http::fake();

        $response = $this->actingAs($user)->getJson(route('calls.leads.conversation', $lead));

        $response->assertOk();
        $response->assertJson(['success' => false]);
        Http::assertNothingSent();
    }

    public function test_the_index_page_only_shows_the_conversation_button_when_ids_are_present(): void
    {
        $withIds = $this->leadWithConversation(['pancake_order_id' => 'c2']);
        $withoutIds = $this->leadWithConversation(['pancake_order_id' => 'c3', 'pancake_page_id' => null, 'pancake_conversation_id' => null, 'customer_name' => 'No Ids']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee("openConversationModal({$withIds->id})", false);
        $response->assertDontSee("openConversationModal({$withoutIds->id})", false);
    }

    public function test_a_tsa_can_send_a_message_on_their_own_lead(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(['success' => true, 'id' => 'm1'], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.conversation.send', $lead), [
            'message' => 'Hi, confirming your order!',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($request) => ($request['action'] ?? null) === 'reply_inbox' && $request['message'] === 'Hi, confirming your order!');
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'message_sent']);
    }

    public function test_a_tsa_cannot_send_a_message_on_another_tsas_lead(): void
    {
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadWithConversation();
        $user   = User::factory()->create(['role' => 'tsa', 'tsa_id' => $mariel->id]);
        Http::fake();

        $this->actingAs($user)->postJson(route('calls.leads.conversation.send', $lead), ['message' => 'Hi'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_sending_a_message_requires_a_non_empty_message(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.conversation.send', $lead), ['message' => '']);

        $response->assertUnprocessable();
    }

    public function test_sending_a_message_on_a_lead_with_no_linked_conversation_fails_without_calling_pancake(): void
    {
        $lead = $this->leadWithConversation(['pancake_page_id' => null, 'pancake_conversation_id' => null]);
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);
        Http::fake();

        $response = $this->actingAs($user)->postJson(route('calls.leads.conversation.send', $lead), ['message' => 'Hi']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        Http::assertNothingSent();
    }

    public function test_sending_a_message_surfaces_pancakes_rejection_eg_outside_the_24_hour_window(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(
                ['success' => false, 'message' => 'Outside messaging window'], 200
            ),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.conversation.send', $lead), ['message' => 'Hi']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error' => 'Outside messaging window']);
        $this->assertDatabaseMissing('lead_activities', ['lead_id' => $lead->id, 'type' => 'message_sent']);
    }
}
