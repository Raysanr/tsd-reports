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

    public function test_a_tsa_can_reply_to_their_own_leads_conversation(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(['success' => true, 'id' => 'sent-1'], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $lead), ['message' => '  Hello po!  ']);

        $response->assertOk()->assertJson(['success' => true, 'id' => 'sent-1']);
        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_contains($req->url(), 'page_access_token=cached-token')
            && $req['action'] === 'reply_inbox'
            && $req['message'] === 'Hello po!'
            && !isset($req['content_ids']));
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'message_sent']);
    }

    public function test_a_reply_that_pancake_rejects_with_http_200_is_reported_as_a_failure(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'cached-token']);
        Http::fake([
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::response(['success' => false, 'message' => 'Outside 24h window'], 200),
        ]);

        $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $lead), ['message' => 'Hi'])
            ->assertStatus(502)
            ->assertJson(['success' => false, 'error' => 'Outside 24h window']);
    }

    public function test_a_reply_retries_once_with_a_fresh_token_when_the_cached_one_is_stale(): void
    {
        $lead = $this->leadWithConversation();
        $user = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);

        PancakePageToken::create(['page_id' => '123', 'page_access_token' => 'stale-token']);
        \App\Models\Setting::set('pancake_access_token', 'user-token');
        Http::fake([
            'pages.fm/api/v1/pages/123/generate_page_access_token*' => Http::response(['page_access_token' => 'fresh-token'], 200),
            'pages.fm/api/public_api/v1/pages/123/conversations/conv-1/messages*' => Http::sequence()
                ->push(['success' => false, 'error_code' => 105, 'message' => 'access_token renewed'], 200)
                ->push(['success' => true, 'id' => 'sent-2'], 200),
        ]);

        $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $lead), ['message' => 'Hi'])
            ->assertOk()->assertJson(['success' => true, 'id' => 'sent-2']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'page_access_token=fresh-token'));
    }

    public function test_a_tsa_cannot_reply_to_another_tsas_lead_conversation(): void
    {
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $lead   = $this->leadWithConversation();
        $user   = User::factory()->create(['role' => 'tsa', 'tsa_id' => $mariel->id]);
        Http::fake();

        $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $lead), ['message' => 'Hi'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_an_empty_reply_or_a_lead_without_a_conversation_never_calls_pancake(): void
    {
        $lead   = $this->leadWithConversation();
        $noConv = $this->leadWithConversation(['pancake_order_id' => 'c9', 'pancake_page_id' => null, 'pancake_conversation_id' => null]);
        $user   = User::factory()->create(['role' => 'tsa', 'tsa_id' => $lead->tsa_id]);
        Http::fake();

        $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $lead), ['message' => '   '])->assertStatus(422);
        $this->actingAs($user)->postJson(route('calls.leads.conversation.reply', $noConv), ['message' => 'Hi'])->assertStatus(422);
        Http::assertNothingSent();
    }
}
