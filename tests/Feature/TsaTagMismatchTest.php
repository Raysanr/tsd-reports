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
}
