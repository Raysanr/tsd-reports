<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\PancakeConversationApi;
use App\Support\PancakeOrderTagApi;
use App\Support\UpsellConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** No Confirmation page (2026-11-13). */
class UpsellConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function upsoldLead(string $orderId, string $upsellAt = '2026-11-13 10:00:00'): Lead
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $lead = Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => "Customer {$orderId}",
            'product_id' => Product::where('display_name', 'SINUXYL')->first()->id,
            'tsa_id' => $gemma->id, 'status' => 'called', 'pancake_created_at' => '2026-11-13 09:00:00',
            'pancake_page_id' => 'page1', 'pancake_conversation_id' => "conv{$orderId}",
        ]);
        LeadActivity::create(['lead_id' => $lead->id, 'type' => 'upsell_added', 'description' => 'Added upsell', 'amount' => 500, 'created_at' => $upsellAt]);

        return $lead;
    }

    /** Pancake message shape: staff has from.admin_id; inserted_at is UTC. */
    private function msg(string $text, string $atUtc, bool $staff = false): array
    {
        return ['message' => $text, 'inserted_at' => $atUtc, 'page_id' => 'page1',
            'from' => $staff ? ['id' => 'staff1', 'admin_id' => 'a1', 'name' => 'Gemma'] : ['id' => 'cust1', 'name' => 'Customer']];
    }

    private function check(Lead $lead, array $messagesResult): Lead
    {
        $conversations = $this->mock(PancakeConversationApi::class);
        $conversations->shouldReceive('getMessages')->andReturn($messagesResult);
        UpsellConfirmation::check($lead, $conversations, $this->mock(PancakeOrderTagApi::class));

        return $lead->refresh();
    }

    public function test_confirmation_words(): void
    {
        foreach (['YES', 'yes po', 'Confirm', 'confirmed na', 'Oo', 'opo', 'Sige po', 'ok', 'Okay po'] as $text) {
            $this->assertTrue(UpsellConfirmation::isConfirmation($text), $text);
        }
        foreach (['Yesterday', 'pakiconfirmahan', 'magkano po', 'book', 'hello'] as $text) {
            $this->assertFalse(UpsellConfirmation::isConfirmation($text), $text);
        }
    }

    public function test_customer_reply_after_upsell_confirms(): void
    {
        // Upsell 10:00 Manila = 02:00 UTC.
        $lead = $this->check($this->upsoldLead('1'), ['success' => true, 'error' => null, 'messages' => [
            $this->msg('Reply YES to confirm', '2026-11-13T02:05:00', staff: true),
            $this->msg('Yes po', '2026-11-13T02:10:00'),
        ]]);

        $this->assertSame('confirmed', $lead->upsell_confirmation);
        $this->assertSame('Yes po', $lead->upsell_confirmation_text);
    }

    public function test_yes_before_the_upsell_or_from_staff_does_not_count(): void
    {
        $lead = $this->check($this->upsoldLead('2'), ['success' => true, 'error' => null, 'messages' => [
            $this->msg('yes', '2026-11-13T01:30:00'),                 // before the upsell
            $this->msg('Reply YES to confirm', '2026-11-13T02:05:00', staff: true),
            $this->msg('magkano po shipping?', '2026-11-13T02:10:00'),
        ]]);

        $this->assertSame('unconfirmed', $lead->upsell_confirmation);
    }

    public function test_unreadable_conversations_are_not_listed(): void
    {
        $unreadable = $this->check($this->upsoldLead('3'), ['success' => false, 'messages' => [], 'error' => 'no permission']);
        $unconfirmed = $this->check($this->upsoldLead('4'), ['success' => true, 'error' => null, 'messages' => []]);
        $this->assertSame('unreadable', $unreadable->upsell_confirmation);

        $user = User::factory()->create(['role' => 'admin']);
        $this->travelTo('2026-11-13 15:00:00');
        $this->app->forgetInstance(PancakeOrderTagApi::class);
        $this->app->offsetUnset(PancakeOrderTagApi::class);

        $this->actingAs($user)->get(route('calls.leads.index', ['view' => 'no_confirmation']))
            ->assertOk()->assertSee('No Confirmation')
            ->assertSee($unconfirmed->customer_name)->assertDontSee($unreadable->customer_name);

        $this->actingAs($user)->getJson('/calls/api/notification-counts')->assertJsonPath('no_confirmation', 1);
    }

    public function test_command_checks_upsold_leads(): void
    {
        $lead = $this->upsoldLead('5', now()->subHour()->toDateTimeString());
        $lead->update(['pancake_created_at' => now()->subHours(2)]);
        Lead::create(['pancake_order_id' => '6', 'customer_name' => 'No upsell', 'status' => 'called',
            'pancake_created_at' => now(), 'pancake_page_id' => 'page1', 'pancake_conversation_id' => 'conv6']);

        $conversations = $this->mock(PancakeConversationApi::class);
        $conversations->shouldReceive('getMessages')->once()->andReturn(['success' => true, 'error' => null, 'messages' => []]);

        $this->artisan('leads:check-upsell-confirmations')->assertSuccessful();

        $this->assertSame('unconfirmed', $lead->refresh()->upsell_confirmation);
        $this->assertNull(Lead::where('pancake_order_id', '6')->first()->upsell_confirmation);
    }

    public function test_reactions_emoji_and_like_sticker_confirm(): void
    {
        $staffAsk = fn (array $extra = []) => $this->msg('Reply YES to confirm', '2026-11-13T02:05:00', staff: true) + $extra;

        // Customer reacted 👍 to the page's message (list of objects).
        $lead = $this->check($this->upsoldLead('7'), ['success' => true, 'error' => null, 'messages' => [
            $staffAsk(['reactions' => [['reaction' => '👍', 'from' => ['id' => 'cust1']]]]),
        ]]);
        $this->assertSame('confirmed', $lead->upsell_confirmation);
        $this->assertSame('Reacted to: Reply YES to confirm', $lead->upsell_confirmation_text);

        // Reaction given by name.
        $this->assertSame('confirmed', $this->check($this->upsoldLead('8'), ['success' => true, 'error' => null, 'messages' => [
            $staffAsk(['reaction' => 'love']),
        ]])->upsell_confirmation);

        // Thumbs-up like sticker from the customer.
        $sticker = $this->msg('', '2026-11-13T02:10:00') + ['attachments' => [['type' => 'sticker', 'url' => 'https://x/369239263222822_n.png']]];
        $this->assertSame('confirmed', $this->check($this->upsoldLead('9'), ['success' => true, 'error' => null, 'messages' => [$sticker]])->upsell_confirmation);

        // Emoji reply.
        $this->assertSame('confirmed', $this->check($this->upsoldLead('10'), ['success' => true, 'error' => null, 'messages' => [
            $this->msg('👍🏻', '2026-11-13T02:10:00'),
        ]])->upsell_confirmation);

        // A reaction on the CUSTOMER's own message, or an angry one, doesn't count.
        $this->assertSame('unconfirmed', $this->check($this->upsoldLead('11'), ['success' => true, 'error' => null, 'messages' => [
            $this->msg('magkano?', '2026-11-13T02:10:00') + ['reactions' => ['👍']],
            $staffAsk(['reactions' => ['😠']]),
        ]])->upsell_confirmation);
    }
}
