<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-21): a user found, by hand, that Pancake POS
 * showed 11 upsell orders tagged "ANGEL" for a day where the TSA Leaderboard
 * only credited Angelica with 10 — and asked to check this for every TSA,
 * not just her. Root cause: SyncTodayOrders::extractTsaInfo() attributes an
 * order primarily by the Pancake account that closed it, only falling back
 * to the order's own name tag when no account matches — so a tagged order
 * can silently end up credited to someone else (or nobody) whenever the
 * account and the tag disagree. This page re-derives "what the tag alone
 * implies" from each order's own already-synced raw_tags and flags every
 * case where that disagrees with the tsa_name sync actually settled on.
 */
class TsaTagMismatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'pancake_created_at' => now(),
            'amount'             => 500,
        ], $overrides));
    }

    public function test_flags_an_order_whose_tag_disagrees_with_who_it_was_credited_to(): void
    {
        // Tagged "GEMMA" (Gemma De Guzman's own tag, seeded by the
        // tsa_shifts migration) but credited to Mariel instead — e.g. Mariel
        // covered and closed the upsell under her own Pancake account.
        $this->order(['pancake_order_id' => 'mismatch-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Mariel']);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));

        $response->assertOk();
        $response->assertSee('#mismatch-1');
        $response->assertSee('Gemma De Guzman');
        $response->assertSee('Mariel Entanto');
    }

    public function test_does_not_flag_an_order_whose_tag_agrees_with_who_it_was_credited_to(): void
    {
        $this->order(['pancake_order_id' => 'agrees-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma']);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));

        $response->assertOk();
        $response->assertDontSee('#agrees-1');
        $response->assertSee('No tag mismatches for this range');
    }

    public function test_flags_an_unattributed_order_that_still_carries_a_tsa_tag(): void
    {
        // Nobody ever claimed this one (tsa_name null — e.g. swept by the
        // midnight uncatered-leads action) even though it still carries
        // Mariel's own tag from whoever tagged it originally.
        $this->order(['pancake_order_id' => 'unattributed-1', 'raw_tags' => ['MARIEL'], 'tsa_name' => null]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));

        $response->assertOk();
        $response->assertSee('#unattributed-1');
        $response->assertSee('— unattributed —', false);
    }

    public function test_an_order_with_no_recognized_tsa_tag_is_never_flagged(): void
    {
        // A tag that matches no TSA at all — nothing for this page to say
        // one way or the other, so it must not show up as a "mismatch".
        $this->order(['pancake_order_id' => 'no-tag-1', 'raw_tags' => ['SOME-PROMO-TAG'], 'tsa_name' => 'Gemma']);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));

        $response->assertOk();
        $response->assertDontSee('#no-tag-1');
    }

    public function test_respects_the_selected_date_range(): void
    {
        $this->order([
            'pancake_order_id'   => 'old-mismatch-1',
            'raw_tags'           => ['GEMMA'],
            'tsa_name'           => 'Mariel',
            'pancake_created_at' => now()->subDays(10),
        ]);

        $today = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));
        $today->assertOk();
        $today->assertDontSee('#old-mismatch-1');

        $ranged = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', [
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to'   => now()->subDays(10)->toDateString(),
        ]));
        $ranged->assertOk();
        $ranged->assertSee('#old-mismatch-1');
    }

    public function test_by_tsa_summary_counts_mismatches_per_tag_implied_tsa(): void
    {
        $this->order(['pancake_order_id' => 'mismatch-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Mariel']);
        $this->order(['pancake_order_id' => 'mismatch-2', 'raw_tags' => ['GEMMA'], 'tsa_name' => null]);
        $this->order(['pancake_order_id' => 'mismatch-3', 'raw_tags' => ['MARIEL'], 'tsa_name' => 'Gemma']);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches'));

        $response->assertOk();
        // Gemma's own tag was overridden twice, Mariel's once.
        $response->assertSeeInOrder(['Gemma De Guzman', '2', 'Mariel Entanto', '1']);
    }

    public function test_non_admin_cannot_view_the_page(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);

        $response = $this->actingAs($normal)->get(route('tsa-tag-mismatches'));

        $response->assertForbidden();
    }

    /**
     * The "By TSA" mismatch summary only ever catches ONE class of gap
     * (tag disagrees with who actually got credited) — explicit request
     * (2026-08-22, live investigation): a user confirmed 11 real orders
     * tagged "ANGEL" in Pancake, all genuinely Angelica's, with ZERO tag
     * mismatches reported here, yet the Leaderboard only counted 10. That
     * proves the gap is a COUNTING exclusion (excluded seller, cancelled
     * add-on, void/restocking status, or the order never actually carrying
     * the real "UPSELL TSD" tag despite a human-readable note saying
     * "WITH UPSELL"), not an attribution one — this ?tsa= audit mode exists
     * to make every one of those reasons directly visible per order,
     * instead of requiring a fifth theory each time this happens again.
     */
    public function test_selecting_a_tsa_shows_every_order_under_her_own_tag_with_a_counted_reason(): void
    {
        // Counts normally — the baseline "nothing wrong here" row.
        $this->order(['pancake_order_id' => 'counted-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma', 'is_upsell' => true]);

        // Tagged GEMMA, correctly attributed to Gemma (no tag mismatch at
        // all), but silently dropped from her upsell count because the item
        // was closed under a known non-TSA account — the exact shape of the
        // Ralph Cruz incident this session already fixed once.
        $this->order([
            'pancake_order_id' => 'excluded-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'excluded_upsell_seller' => true,
        ]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', ['tsa' => 'Gemma']));

        $response->assertOk();
        $response->assertSee('#counted-1');
        $response->assertSee('#excluded-1');
        $response->assertSee('excluded seller', false);
    }

    public function test_tsa_audit_explains_a_cancelled_upsell_add_on(): void
    {
        $this->order([
            'pancake_order_id' => 'cancelled-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_cancelled_upsell' => true,
        ]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', ['tsa' => 'Gemma']));

        $response->assertOk();
        $response->assertSee('#cancelled-1');
        $response->assertSee('cancelled', false);
    }

    public function test_tsa_audit_explains_a_void_status_order(): void
    {
        $this->order([
            'pancake_order_id' => 'voided-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'status_code' => 6,
        ]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', ['tsa' => 'Gemma']));

        $response->assertOk();
        $response->assertSee('#voided-1');
        $response->assertSee('Canceled', false);
    }

    public function test_tsa_audit_explains_an_order_with_no_real_upsell_tag(): void
    {
        // Carries Gemma's own name tag but never carried the actual
        // "UPSELL TSD" Pancake tag the sync reads — e.g. a plain lead she
        // called that never upsold, or a human note said "WITH UPSELL"
        // without the real tag ever being applied.
        $this->order([
            'pancake_order_id' => 'no-real-tag-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Gemma',
            'is_upsell' => false,
        ]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', ['tsa' => 'Gemma']));

        $response->assertOk();
        $response->assertSee('#no-real-tag-1');
        $response->assertSee('No &quot;UPSELL TSD&quot; tag', false);
    }

    public function test_tsa_audit_still_shows_an_order_credited_to_someone_else(): void
    {
        // A tag mismatch is still a valid reason an order doesn't show up
        // under this TSA's own count — the audit view must surface it too,
        // not just the exclusion-style reasons.
        $this->order(['pancake_order_id' => 'stolen-1', 'raw_tags' => ['GEMMA'], 'tsa_name' => 'Mariel', 'is_upsell' => true]);

        $response = $this->actingAs($this->admin())->get(route('tsa-tag-mismatches', ['tsa' => 'Gemma']));

        $response->assertOk();
        $response->assertSee('#stolen-1');
        $response->assertSee('Mariel Entanto');
    }
}
