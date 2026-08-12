<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkSeparateParcelOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_in_a_missing_tsa_and_team_from_a_tagged_sibling_order(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-1',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'upsell-1',
            'customer_phone'     => '09171234567',
            'team'               => null,
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $orphan->refresh();
        $this->assertSame('Joana', $orphan->tsa_name);
        $this->assertSame('Eyecare Team', $orphan->team);
    }

    public function test_tolerates_the_seperate_misspelling(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-2',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPERATE PARCEL TSD'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'upsell-2',
            'customer_phone'     => '09171234567',
            'tsa_name'           => null,
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $this->assertSame('Joana', $orphan->refresh()->tsa_name);
    }

    public function test_does_not_link_orders_without_the_trigger_tag_anywhere_in_the_group(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-3',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA'], // no separate-parcel tag anywhere
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'unrelated-1',
            'customer_phone'     => '09171234567',
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $this->assertNull($orphan->refresh()->tsa_name);
    }

    public function test_never_overwrites_an_order_that_already_has_its_own_attribution(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-4',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $alreadyAttributed = Order::factory()->create([
            'pancake_order_id'   => 'sibling-already-set',
            'customer_phone'     => '09171234567',
            'team'               => 'SH Naturals',
            'tsa_name'           => 'Gemma',
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        // Untouched — 'Gemma' is a genuinely different, already-real signal,
        // not something to clobber with 'Joana' just because they share a
        // customer/day and one order in the group has the trigger tag.
        $this->assertSame('Gemma', $alreadyAttributed->refresh()->tsa_name);
        $this->assertSame('SH Naturals', $alreadyAttributed->refresh()->team);
    }

    public function test_skips_a_group_with_conflicting_tsa_signals_rather_than_guessing(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-5',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        Order::factory()->create([
            'pancake_order_id'   => 'conflict-1',
            'customer_phone'     => '09171234567',
            'team'               => 'SH Naturals',
            'tsa_name'           => 'Gemma',
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'orphan-in-conflict-group',
            'customer_phone'     => '09171234567',
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:10:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        // Two disagreeing TSA names in the same group — nothing gets touched,
        // including the genuinely orphaned third order.
        $this->assertNull($orphan->refresh()->tsa_name);
    }

    public function test_does_not_link_orders_from_different_calendar_days(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-6',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $laterOrder = Order::factory()->create([
            'pancake_order_id'   => 'different-day-1',
            'customer_phone'     => '09171234567',
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-13 09:00:00', // 2 days later, same phone
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $this->assertNull($laterOrder->refresh()->tsa_name);
    }

    public function test_does_not_link_orders_from_different_phone_numbers(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-7',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $otherCustomer = Order::factory()->create([
            'pancake_order_id'   => 'different-phone-1',
            'customer_phone'     => '09179999999',
            'tsa_name'           => null,
            'raw_tags'           => [],
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();

        $this->assertNull($otherCustomer->refresh()->tsa_name);
    }

    public function test_ignores_orders_with_no_phone_number_at_all(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-8',
            'customer_phone'     => null,
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        // Should not error out on a null-phone order sitting in the candidate pool.
        $this->artisan('pancake:link-parcels')->assertSuccessful();
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        Order::factory()->create([
            'pancake_order_id'   => 'base-9',
            'customer_phone'     => '09171234567',
            'team'               => 'Eyecare Team',
            'tsa_name'           => 'Joana',
            'raw_tags'           => ['JOANA', 'SEPARATE PARCEL'],
            'pancake_created_at' => '2026-08-11 14:00:00',
        ]);

        $orphan = Order::factory()->create([
            'pancake_order_id'   => 'upsell-9',
            'customer_phone'     => '09171234567',
            'tsa_name'           => null,
            'pancake_created_at' => '2026-08-11 14:05:00',
        ]);

        $this->artisan('pancake:link-parcels')->assertSuccessful();
        $this->assertSame('Joana', $orphan->refresh()->tsa_name);

        // Second run: already-filled sibling now has its own tsa_name, so this
        // is functionally the "already attributed" case — must stay 'Joana',
        // not get cleared or altered.
        $this->artisan('pancake:link-parcels')->assertSuccessful();
        $this->assertSame('Joana', $orphan->refresh()->tsa_name);
    }
}
