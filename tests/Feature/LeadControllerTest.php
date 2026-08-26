<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class LeadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_only_sees_their_own_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);

        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Mariel Lead');
    }

    public function test_a_leads_phone_number_carries_their_tsas_dialer_host_when_set(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['dialer_host' => '192.168.1.42:8080']);
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('data-dial-host="192.168.1.42:8080"', false);
    }

    public function test_a_leads_phone_number_has_no_dialer_host_when_their_tsa_never_configured_one(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('data-dial-host=""', false);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_date_range_filters_leads_by_pancake_created_at(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'In Range', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2026-08-05 10:00:00']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Out Of Range', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2026-08-01 10:00:00']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['date_from' => '2026-08-04', 'date_to' => '2026-08-06']));

        $response->assertOk();
        $response->assertSee('In Range');
        $response->assertDontSee('Out Of Range');
    }

    public function test_no_date_range_shows_every_lead_same_as_before(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Old Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2020-01-01 10:00:00']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date2@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Old Lead');
    }

    public function test_a_date_range_does_not_apply_to_the_overdue_view(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Old Overdue', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(10),
            'pancake_created_at' => '2020-01-01 10:00:00',
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date3@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        // A date range that would exclude this lead by creation date is
        // irrelevant here — Overdue view ignores it entirely.
        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['view' => 'overdue', 'date_from' => '2026-08-01', 'date_to' => '2026-08-06']));

        $response->assertOk();
        $response->assertSee('Old Overdue');
    }

    /**
     * Explicit request, 2026-08-26: "the only leads should be is all new
     * status" — a lead stays in the active queue only while its underlying
     * Pancake order is still New (status_code 0). Real examples reported:
     * orders that had gone Returned/Received days earlier were still
     * cluttering today's queue.
     */
    public function test_a_lead_whose_order_is_still_new_stays_in_the_queue(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Still New', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::create(['pancake_order_id' => '1', 'status_code' => 0, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Still New');
    }

    public function test_a_lead_whose_order_has_moved_past_new_is_hidden_from_the_queue(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Already Returned', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Order::create(['pancake_order_id' => '1', 'status_code' => 5, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertDontSee('Already Returned');
    }

    public function test_a_lead_with_no_matching_order_row_yet_still_shows(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '999', 'customer_name' => 'Not Synced Yet', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Not Synced Yet');
    }

    public function test_an_already_called_lead_stays_visible_regardless_of_its_orders_current_status(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Already Called', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called']);
        Order::create(['pancake_order_id' => '1', 'status_code' => 5, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'called']));

        $response->assertOk();
        $response->assertSee('Already Called');
    }

    public function test_an_admin_sees_every_tsas_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin2@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertSee('Mariel Lead');
    }

    public function test_a_tsa_can_log_an_outcome_on_their_own_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma3@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('called', $lead->status);
        $this->assertSame('Confirmed', $lead->disposition);
        $this->assertNotNull($lead->called_at);
        $this->assertSame($user->id, $lead->called_by_user_id);
    }

    public function test_a_tsa_cannot_log_an_outcome_on_someone_elses_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma4@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertForbidden();
    }

    public function test_a_tsa_cannot_reach_settings(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma5@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('settings'))->assertForbidden();
    }

    public function test_a_table_refresh_request_returns_only_the_table_fragment_not_the_full_layout(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma6@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'), ['X-Table-Refresh' => '1']);

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Call Tracker', false);
        $response->assertDontSee('id="leads-table-container"', false);
    }

    public function test_a_table_refresh_request_still_respects_the_same_tsa_scoping_as_a_normal_request(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma7@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'), ['X-Table-Refresh' => '1']);

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Mariel Lead');
    }

    private function fakePosTags(array $tags, array $overrides = []): void
    {
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
        Http::fake(array_merge([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => $tags], 200),
            'pos.pages.fm/api/v1/shops/4/orders/1*' => Http::response(['success' => true, 'data' => ['id' => 1, 'tags' => []]], 200),
        ], $overrides));
    }

    public function test_logging_an_outcome_tags_both_the_disposition_and_the_tsa_on_the_real_pos_order(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 11, 'name' => 'Gemma'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma8@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            return in_array(10, $tagIds, true) && in_array(11, $tagIds, true); // Confirmed + Gemma
        });
    }

    public function test_logging_an_outcome_with_multiple_picked_tags_writes_every_one_of_them_plus_the_tsa_to_pancake(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 20, 'name' => 'Call Back'],
            ['id' => 11, 'name' => 'Gemma'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma15@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed, Call Back']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed, Call Back', $lead->disposition);
        // "Call Back" being one of several picked tags (not the only one)
        // still schedules a callback due date.
        $this->assertNotNull($lead->callback_at);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            return in_array(10, $tagIds, true) && in_array(20, $tagIds, true) && in_array(11, $tagIds, true);
        });
    }

    public function test_logging_an_outcome_is_rejected_when_any_one_of_several_picked_tags_isnt_real(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma16@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        // 'Confirmed' is real but 'Made Up Tag' isn't — the whole submission
        // is rejected, not just the invalid half of it.
        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed, Made Up Tag']);

        $response->assertSessionHasErrors('disposition');
        $this->assertNull($lead->refresh()->disposition);
    }

    public function test_logging_an_outcome_on_a_lead_still_saves_locally_when_pancake_isnt_connected(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');
        Http::fake(); // no stubs — any real call fails the test

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma9@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed', $lead->disposition);
        Http::assertNothingSent();
    }

    public function test_logging_an_outcome_is_rejected_when_the_text_isnt_a_real_tag_on_the_shop(): void
    {
        $this->fakePosTags([
            ['id' => 1, 'name' => 'Someone Else'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma10@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        // 'Confirmed' was never really picked from the search box's real
        // suggestions (the only real tag on this shop is 'Someone Else') —
        // the request is rejected rather than silently saved as junk.
        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertSessionHasErrors('disposition');
        $lead->refresh();
        $this->assertNull($lead->disposition);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_logging_an_outcome_is_accepted_when_pancakes_tag_catalog_cant_be_fetched(): void
    {
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
        Http::fake([
            // Catalog fetch itself fails (e.g. Pancake down / API key
            // revoked) — no real list to validate against, so the
            // disposition is trusted as-is rather than blocking the TSA's
            // save entirely.
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['message' => 'error'], 500),
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma11@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed', $lead->disposition);
    }

    public function test_searching_tags_returns_the_shops_real_pos_tag_catalog_filtered_by_query(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 11, 'name' => 'Call Back'],
            ['id' => 12, 'name' => 'Not Interested'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma12@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.tags', $lead) . '?q=call');

        $response->assertOk();
        $response->assertJson(['success' => true, 'tags' => [['id' => 11, 'text' => 'Call Back', 'color' => null]]]);
    }

    public function test_searching_tags_on_a_lead_with_no_pancake_order_returns_an_empty_list(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        // pancake_order_id is a required, unique column on every real synced
        // lead — an empty string is the only DB-valid way to exercise this
        // "somehow has none" guard without violating that constraint.
        $lead->forceFill(['pancake_order_id' => ''])->save();
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma13@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.tags', $lead));

        $response->assertOk();
        $response->assertJson(['success' => false, 'tags' => []]);
    }

    public function test_a_tsa_cannot_search_tags_on_someone_elses_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma14@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.leads.tags', $lead))->assertForbidden();
    }
}
