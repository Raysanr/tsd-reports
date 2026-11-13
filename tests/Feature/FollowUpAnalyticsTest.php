<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\FollowUpAnalytics;
use App\Support\LeadCallResults;
use App\Support\PancakeOrderTagApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 2026-11-13: Analytics' Unanswered-call follow-up. */
class FollowUpAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private TsaShift $tsa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsa = TsaShift::where('team', 'SH Naturals')->firstOrFail();
    }

    private function lead(string $orderId, string $phone): Lead
    {
        return Lead::create([
            'pancake_order_id' => $orderId, 'customer_name' => "Customer {$orderId}", 'phone_number' => $phone,
            'tsa_id' => $this->tsa->id, 'status' => 'called', 'pancake_created_at' => '2026-11-12 09:00:00',
        ]);
    }

    private function activity(Lead $lead, string $type, string $description, string $at): void
    {
        LeadActivity::create(['lead_id' => $lead->id, 'type' => $type, 'description' => $description, 'created_at' => $at]);
    }

    private function unansweredTag(Lead $lead, string $tag, string $at): void
    {
        $this->activity($lead, 'callback_scheduled', "Tagged for Unanswered Calls (Pancake tag \"{$tag}\", not a logged Outcome).", $at);
    }

    private function dial(Lead $lead, string $at): void
    {
        $this->activity($lead, 'call_clicked', 'Joana clicked to call Customer.', $at);
    }

    /** Same record shapes as production (checked 2026-11-13). */
    public function test_it_follows_unanswered_first_calls(): void
    {
        // Dialed, tagged Not answering, dialed again, then confirmed.
        $converted = $this->lead('1001', '09170000001');
        $this->dial($converted, '2026-11-12 09:05:00');
        $this->unansweredTag($converted, 'Not answering ', '2026-11-12 09:10:00');
        $this->dial($converted, '2026-11-12 10:10:00');
        $this->activity($converted, 'tag_added', 'Added tag "CONFIRMED VIA CALL" by Joana.', '2026-11-12 10:15:00');

        // Retried only by a phone call event matched by number.
        $phoneOnly = $this->lead('1002', '+63 917 000 0002');
        $this->unansweredTag($phoneOnly, 'UNATTENDED - EYECARE', '2026-11-12 09:20:00');
        CallEvent::create(['tsa_id' => $this->tsa->id, 'phone_number' => '09170000002', 'direction' => 'outgoing', 'occurred_at' => '2026-11-12 09:50:00']);
        CallEvent::create(['tsa_id' => $this->tsa->id, 'phone_number' => '09170000002', 'direction' => 'outgoing', 'occurred_at' => '2026-11-12 09:15:00']); // before the result

        // Never retried (only the first dial).
        $never = $this->lead('1003', '09170000003');
        $this->dial($never, '2026-11-12 09:25:00');
        $this->unansweredTag($never, 'Not answering ', '2026-11-12 09:30:00');

        // First result answered (Call Back): not part of follow-up; product tags ignored.
        $answered = $this->lead('1004', '09170000004');
        $this->activity($answered, 'tag_added', 'Added tag "PTERYGIUM" by Joana.', '2026-11-12 09:35:00');
        $this->activity($answered, 'callback_scheduled', 'Callback due now (Pancake tag "CALL BACK", not a logged Outcome).', '2026-11-12 09:40:00');

        // Unanswered first, retried, final result Call Back, and the order became a real upsell.
        $upsold = $this->lead('1005', '09170000005');
        $this->unansweredTag($upsold, 'Unattended', '2026-11-12 09:00:00');
        $this->dial($upsold, '2026-11-12 11:00:00');
        $upsold->update(['disposition' => 'CALL BACK']);
        Order::create(['pancake_order_id' => '1005', 'team' => 'SH Naturals', 'tsa_name' => $this->tsa->tsa_key, 'product' => 'Sinuxyl',
            'is_upsell' => true, 'amount' => 500, 'status_code' => 1, 'raw_tags' => [], 'pancake_created_at' => '2026-11-12 09:00:00', 'synced_at' => now()]);

        $data = FollowUpAnalytics::build(Carbon::parse('2026-11-12')->startOfDay(), Carbon::parse('2026-11-12')->endOfDay());
        $t = $data['totals'];

        $this->assertSame([4, 3, 2, 2, 1], [$t['first_unanswered'], $t['retried'], $t['answered'], $t['converted'], $t['never_retried']]);
        $this->assertSame(75.0, $t['retried_pct']);
        $this->assertSame((int) round((60 + 30 + 120) / 3), $t['avg_retry_min']);
        $this->assertSame(['1003'], array_column($data['neverRetried'], 'order_id'));
        $this->assertSame($this->tsa->display_name, $data['byTsa'][0]['name']);
    }

    public function test_the_follow_up_tab_renders(): void
    {
        $lead = $this->lead('2001', '09170000009');
        $this->unansweredTag($lead, 'Not answering ', '2026-11-12 09:10:00');
        Order::create(['pancake_order_id' => '2001', 'team' => 'SH Naturals', 'tsa_name' => $this->tsa->tsa_key, 'product' => 'Sinuxyl',
            'disposition' => 'NOT ANSWERING', 'raw_tags' => ['NOT ANSWERING'], 'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => '2026-11-12 09:00:00', 'synced_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->get(route('charts', ['date_from' => '2026-11-12', 'date_to' => '2026-11-12']))
            ->assertOk()->assertSee('Follow-up')->assertSee('Unanswered-Call Follow-up')->assertSee('2001');
    }

    private function tagChange(array $old, array $new, string $atUtc, ?string $editor = null): array
    {
        $names = fn ($l) => array_map(fn ($n) => ['id' => crc32($n), 'name' => $n], $l);

        return ['tags' => ['old' => $names($old), 'new' => $names($new)], 'updated_at' => $atUtc, 'editor_id' => $editor];
    }

    /** Pancake history: exact times, untracked leads, quick add/remove, tagging TSA credited. */
    public function test_it_uses_pancake_tag_history(): void
    {
        $other = TsaShift::where('id', '!=', $this->tsa->id)->firstOrFail();
        $other->update(['pos_user_id' => 'pos-other']);

        // Status 'called' + already had a disposition: the sync never logged this one.
        $lead = $this->lead('3001', '09170000031');
        $lead->update(['disposition' => 'CALL BACK']);
        // Tagged Not answering at 09:10 Manila by the other TSA, removed a minute later
        // (between sync ticks), then the lead was reassigned.
        LeadCallResults::recordFromHistories($lead, [
            $this->tagChange(['SINUXYL'], ['SINUXYL', 'Not answering'], '2026-11-12T01:10:00', 'pos-other'),
            $this->tagChange(['SINUXYL', 'Not answering'], ['SINUXYL'], '2026-11-12T01:11:00', 'pos-other'),
        ]);
        // The sync noticed the earlier dial only later; retry 2 min after the real tag.
        $this->dial($lead, '2026-11-12 09:12:00');

        // Re-running is harmless.
        LeadCallResults::recordFromHistories($lead, [
            $this->tagChange(['SINUXYL'], ['SINUXYL', 'Not answering'], '2026-11-12T01:10:00', 'pos-other'),
        ]);

        $data = FollowUpAnalytics::build(Carbon::parse('2026-11-12')->startOfDay(), Carbon::parse('2026-11-12')->endOfDay());

        $this->assertSame(1, $data['totals']['first_unanswered']);
        $this->assertSame(1, $data['totals']['retried']);
        $this->assertSame(2, $data['totals']['avg_retry_min']);
        $this->assertSame($other->display_name, $data['byTsa'][0]['name']);
    }

    /** A number-only call goes to the customer's latest lead, not an older order. */
    public function test_phone_calls_belong_to_the_latest_lead_for_that_number(): void
    {
        $old = $this->lead('4001', '09170000041');
        $this->unansweredTag($old, 'Not answering ', '2026-11-12 09:10:00');
        $new = Lead::create(['pancake_order_id' => '4002', 'customer_name' => 'Customer 4002', 'phone_number' => '09170000041',
            'tsa_id' => $this->tsa->id, 'status' => 'new', 'pancake_created_at' => '2026-11-12 12:00:00']);
        CallEvent::create(['tsa_id' => $this->tsa->id, 'phone_number' => '09170000041', 'direction' => 'outgoing', 'occurred_at' => '2026-11-12 12:05:00']);

        $data = FollowUpAnalytics::build(Carbon::parse('2026-11-12')->startOfDay(), Carbon::parse('2026-11-12')->endOfDay());

        $this->assertSame(0, $data['totals']['retried']);
        $this->assertSame(['4001'], array_column($data['neverRetried'], 'order_id'));
    }

    /** The sync reads Pancake history only when a lead's result tags change. */
    public function test_sync_refreshes_only_on_result_tag_change(): void
    {
        $lead = $this->lead('5001', '09170000051');
        $api = $this->mock(PancakeOrderTagApi::class);
        $api->shouldReceive('getOrderHistories')->once()->with('5001')->andReturn([
            $this->tagChange([], ['Not answering'], '2026-11-12T01:10:00'),
        ]);

        $raw = ['tags' => [['name' => 'SINUXYL'], ['name' => 'Not answering']]];
        $this->assertTrue(LeadCallResults::refreshIfChanged($lead, $raw, $api));
        $this->assertFalse(LeadCallResults::refreshIfChanged($lead->fresh(), $raw, $api));
        $this->assertFalse(LeadCallResults::refreshIfChanged($lead->fresh(), ['tags' => [['name' => 'SINUXYL'], ['name' => 'NOT ANSWERING']]], $api));

        $this->assertDatabaseHas('lead_call_results', ['lead_id' => $lead->id, 'result' => 'NOT ANSWERING', 'tsa_id' => $this->tsa->id]);
    }
}
