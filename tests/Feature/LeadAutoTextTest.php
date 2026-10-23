<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\LeadAutoText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Auto-text on new lead via each TSA's own SMSGate phone (2026-10-23). */
class LeadAutoTextTest extends TestCase
{
    use RefreshDatabase;

    private TsaShift $tsa;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('calltracker:reconcile-roster');
        LeadAutoText::flushOnlineMemo();

        $this->tsa = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->tsa->update(['sms_enabled' => true, 'smsgate_username' => 'GEMMA1', 'smsgate_password' => 'secret']);
        $this->product = Product::where('display_name', 'SINUXYL')->first();
        $this->product->update(['sms_template' => 'Hi {name}! Re your {product}, {tsa} will call you shortly.']);
        Setting::set(LeadAutoText::SETTING_KEY, true);
    }

    private function lead(string $phone = '0917 123 4567'): Lead
    {
        return Lead::create([
            'pancake_order_id' => 'sms-' . uniqid(), 'customer_name' => 'Maria Santos', 'phone_number' => $phone,
            'product_id' => $this->product->id, 'tsa_id' => $this->tsa->id, 'status' => 'assigned',
        ]);
    }

    private function fakeSmsGate(string $lastSeen = null, int $sendStatus = 202): void
    {
        Http::fake([
            'api.sms-gate.app/3rdparty/v1/devices' => Http::response([['id' => 'dev1', 'lastSeen' => $lastSeen ?? now()->toIso8601String()]]),
            'api.sms-gate.app/3rdparty/v1/messages' => Http::response(['id' => 'msg1', 'state' => 'Pending'], $sendStatus),
        ]);
    }

    public function test_sends_the_products_message_from_the_tsas_own_phone(): void
    {
        $this->fakeSmsGate();
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages')
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('GEMMA1:secret'))
            && $r['phoneNumbers'] === ['+639171234567']
            && $r['textMessage']['text'] === "Hi Maria! Re your SINUXYL, {$this->tsa->display_name} will call you shortly.");
        $this->assertTrue($lead->activities()->where('type', 'sms_sent')->exists());
    }

    public function test_offline_phone_is_skipped_and_logged(): void
    {
        $this->fakeSmsGate(now()->subHour()->toIso8601String());
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messages'));
        $this->assertStringContainsString('offline', $lead->activities()->where('type', 'sms_skipped')->value('description'));
    }

    public function test_global_switch_off_sends_nothing_and_logs_nothing(): void
    {
        Setting::set(LeadAutoText::SETTING_KEY, false);
        $this->fakeSmsGate();
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        Http::assertNothingSent();
        $this->assertFalse($lead->activities()->whereIn('type', ['sms_sent', 'sms_skipped'])->exists());
    }

    public function test_tsa_switch_off_sends_nothing(): void
    {
        $this->tsa->update(['sms_enabled' => false]);
        $this->fakeSmsGate();

        LeadAutoText::sendForNewLead($this->lead());

        Http::assertNothingSent();
    }

    public function test_product_without_a_message_is_skipped_and_logged(): void
    {
        $this->product->update(['sms_template' => null]);
        $this->fakeSmsGate();
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        Http::assertNothingSent();
        $this->assertTrue($lead->activities()->where('type', 'sms_skipped')->exists());
    }

    public function test_invalid_number_is_skipped_and_logged(): void
    {
        $this->fakeSmsGate();
        $lead = $this->lead('12345');

        LeadAutoText::sendForNewLead($lead);

        Http::assertNothingSent();
        $this->assertStringContainsString('invalid phone', $lead->activities()->where('type', 'sms_skipped')->value('description'));
    }

    public function test_rejected_login_is_logged_not_thrown(): void
    {
        Http::fake(['api.sms-gate.app/*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        $this->assertTrue($lead->activities()->where('type', 'sms_skipped')->exists());
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('+639171234567', LeadAutoText::normalizePhone('09171234567'));
        $this->assertSame('+639171234567', LeadAutoText::normalizePhone('+63 917 123 4567'));
        $this->assertSame('+639171234567', LeadAutoText::normalizePhone('9171234567'));
        $this->assertNull(LeadAutoText::normalizePhone('0281234567'));
        $this->assertNull(LeadAutoText::normalizePhone(null));
    }

    public function test_tsa_management_saves_sms_settings_and_keeps_password_when_blank(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $products = $this->tsa->products()->pluck('products.id')->all();

        $this->post(route('calls.tsa-management.update', $this->tsa), [
            'products' => $products, 'sms_enabled' => '0', 'smsgate_username' => 'NEWUSER', 'smsgate_password' => '',
        ])->assertRedirect();

        $fresh = $this->tsa->fresh();
        $this->assertFalse($fresh->sms_enabled);
        $this->assertSame('NEWUSER', $fresh->smsgate_username);
        $this->assertSame('secret', $fresh->smsgate_password);
    }

    public function test_global_toggle_and_product_message_save(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson(route('calls.tsa-management.toggle-auto-sms'), ['enabled' => '0'])->assertOk();
        $this->assertFalse(LeadAutoText::enabled());

        $this->postJson(route('calls.tsa-management.sms-template', $this->product), ['sms_template' => '  Hello {name}  '])->assertOk();
        $this->assertSame('Hello {name}', $this->product->fresh()->sms_template);

        $this->get(route('calls.tsa-management'))->assertOk()->assertSee('Auto-text messages')->assertDontSee('secret');
    }

    public function test_all_placeholders_render(): void
    {
        $lead = $this->lead();
        $lead->update(['customer_name' => 'Maria  Dela Cruz', 'customer_address' => 'Brgy 1, Quezon City']);

        $this->assertSame(
            'Maria|Dela Cruz|Maria Dela Cruz|0917 123 4567|Brgy 1, Quezon City|SINUXYL|Maria',
            LeadAutoText::render('{first_name}|{last_name}|{full_name}|{phone}|{address}|{product}|{name}', $lead->fresh())
        );
    }

    public function test_message_needing_a_missing_address_is_skipped_and_logged(): void
    {
        $this->product->update(['sms_template' => 'Hi {first_name}, deliver to {address}?']);
        $this->fakeSmsGate();
        $lead = $this->lead();

        LeadAutoText::sendForNewLead($lead);

        Http::assertNothingSent();
        $this->assertStringContainsString('no address', $lead->activities()->where('type', 'sms_skipped')->value('description'));
    }
}
