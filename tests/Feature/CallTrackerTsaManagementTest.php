<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) as
 * CallTrackerTsaManagementTest — renamed from call-tracker's own
 * TsaManagementTest because tsd-reports already has a similarly-named
 * TsaManagementControllerTest.php for its own, unrelated shift/rest-day
 * roster controller (same underlying tsa_shifts rows, different concern —
 * this ported page is "Call Rotation", route calls.tsa-management).
 * Tsa -> TsaShift, routes -> calls.tsa-management.*.
 */
class CallTrackerTsaManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * NOTE (adapted, not verbatim): the merged app's product_tsa table is
     * deliberately NOT seeded by any migration (unlike call-tracker's
     * original, which owned fresh products/tsas tables it seeded
     * directly) — it's wired up by the one-time `calltracker:reconcile-roster`
     * command (Phase 4), matching call-tracker's 7 seed products/TSAs
     * against tsd-reports' pre-existing rows by name/key. Several tests
     * below (listing/checking/unchecking a TSA's product assignments) need
     * that reconciler to have run first.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('calltracker:reconcile-roster');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_tsa_cannot_reach_tsa_management(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.tsa-management'))->assertForbidden();
    }

    public function test_the_index_page_lists_every_tsa_with_their_current_product_assignments(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('calls.tsa-management'));

        $response->assertOk();
        $response->assertSee('Gemma De Guzman');
        $response->assertSee('SINUXYL');
        $response->assertSee('PTERYGIUM');
    }

    public function test_checking_a_new_product_appends_the_tsa_to_the_end_of_its_rotation(): void
    {
        $this->actingAs($this->admin());

        $gemma       = TsaShift::where('tsa_key', 'Gemma')->first();
        $clearSight  = Product::where('display_name', 'CLEARSIGHT')->first();
        // Gemma isn't on Clear Sight's roster yet (seeded to Eyecare TSAs only).
        $existing    = $clearSight->tsas()->orderByPivot('position')->get();

        $response = $this->post(route('calls.tsa-management.update', $gemma), [
            'active'   => '1',
            'products' => $gemma->products()->pluck('products.id')->push($clearSight->id)->all(),
        ]);

        $response->assertRedirect(route('calls.tsa-management'));

        $gemma->refresh();
        $this->assertTrue($gemma->products->contains('id', $clearSight->id));

        $pivot = $gemma->products()->where('products.id', $clearSight->id)->first()->pivot;
        $this->assertSame($existing->max('pivot.position') + 1, $pivot->position);
    }

    public function test_unchecking_a_product_removes_the_tsa_from_its_rotation(): void
    {
        $this->actingAs($this->admin());

        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $this->assertTrue($gemma->products->contains('id', $sinuxyl->id));

        $remaining = $gemma->products()->pluck('products.id')->reject(fn ($id) => $id === $sinuxyl->id);

        $this->post(route('calls.tsa-management.update', $gemma), [
            'active'   => '1',
            'products' => $remaining->all(),
        ]);

        $gemma->refresh();
        $this->assertFalse($gemma->products->contains('id', $sinuxyl->id));
    }

    public function test_updating_a_tsa_saves_their_phone_number(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        $this->post(route('calls.tsa-management.update', $gemma), [
            'active'       => '1',
            'phone_number' => '09171234567',
            'products'     => $gemma->products()->pluck('products.id')->all(),
        ]);

        $this->assertSame('09171234567', $gemma->fresh()->phone_number);
    }

    public function test_updating_a_tsa_saves_their_dialer_host(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();

        $this->post(route('calls.tsa-management.update', $gemma), [
            'active'      => '1',
            'dialer_host' => '192.168.1.42:8080',
            'products'    => $gemma->products()->pluck('products.id')->all(),
        ]);

        $this->assertSame('192.168.1.42:8080', $gemma->fresh()->dialer_host);
    }

    /**
     * NEW (Phase 9 plan): this ported controller shares the same tsa_shifts
     * table/row as tsd-reports' own pre-existing shift/rest-day roster
     * (TsaManagementController, route('tsa-management')) — prove that
     * updating a TSA's phone/dialer_host through THIS controller
     * (CallTracker\TsaManagementController::update(), which never touches
     * shift_start/rest_day_of_week) leaves those pre-existing fields
     * completely untouched on the same row.
     */
    public function test_updating_phone_and_dialer_host_does_not_disturb_the_tsas_existing_shift_and_rest_day_fields(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['shift_start' => '09:00:00', 'shift_end' => '18:00:00', 'rest_day_of_week' => 'sunday']);

        $this->post(route('calls.tsa-management.update', $gemma), [
            'active'       => '1',
            'phone_number' => '09171234567',
            'dialer_host'  => '192.168.1.42:8080',
            'products'     => $gemma->products()->pluck('products.id')->all(),
        ]);

        $gemma->refresh();
        $this->assertSame('09171234567', $gemma->phone_number);
        $this->assertSame('192.168.1.42:8080', $gemma->dialer_host);
        // Untouched by this controller — same row, different concern.
        $this->assertSame('09:00:00', $gemma->shift_start);
        $this->assertSame('18:00:00', $gemma->shift_end);
        $this->assertSame('sunday', $gemma->rest_day_of_week);
    }

    public function test_regenerating_a_tsas_api_token_issues_a_fresh_one(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->assertNull($gemma->api_token);

        $response = $this->post(route('calls.tsa-management.regenerate-token', $gemma));

        $response->assertRedirect(route('calls.tsa-management'));
        $gemma->refresh();
        $this->assertNotNull($gemma->api_token);
        $firstToken = $gemma->api_token;

        // Regenerating again issues a genuinely different token, not a no-op.
        $this->post(route('calls.tsa-management.regenerate-token', $gemma));
        $this->assertNotSame($firstToken, $gemma->fresh()->api_token);
    }

    public function test_regenerating_a_token_via_ajax_returns_the_rendered_token_card(): void
    {
        // Explicit request, 2026-08-27: "i want when in every generate
        // token it is not resetting the whole page ... a small pop up" —
        // postJson() sends Accept: application/json, taking the same
        // wantsJson() branch the "Save" form's own AJAX handler already
        // uses, instead of the old full-page redirect.
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->assertNull($gemma->api_token);

        $response = $this->postJson(route('calls.tsa-management.regenerate-token', $gemma));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $gemma->refresh();

        // The returned HTML fragment reflects the NEW state (token fields +
        // setup guide), not the pre-regeneration "No token yet" line — the
        // whole reason this returns re-rendered HTML instead of just the
        // bare token string is that generating a token swaps which BLOCK of
        // markup shows, not just one field's text.
        $html = $response->json('html');
        $this->assertStringContainsString($gemma->api_token, $html);
        $this->assertStringContainsString('MacroDroid setup guide', $html);
        $this->assertStringNotContainsString('No token yet', $html);
    }

    public function test_a_tsa_cannot_regenerate_an_api_token(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->post(route('calls.tsa-management.regenerate-token', $gemma))->assertForbidden();
    }

    /**
     * Explicit request (2026-08-26), a follow-up to the "give a TSA a
     * login" attempt reverted earlier the same day: confirmed live (User
     * Management screenshot) every TSA already has a real account, role
     * 'normal', just never linked to their tsa_shifts row. This connects
     * an EXISTING account rather than creating a new one.
     */
    public function test_an_admin_can_link_an_existing_account_to_a_tsa(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $account = User::factory()->create(['name' => 'Gemma De Guzman', 'role' => 'normal', 'tsa_id' => null]);

        $response = $this->post(route('calls.tsa-management.link-user', $gemma), ['user_id' => $account->id]);

        $response->assertRedirect(route('calls.tsa-management'));
        $this->assertSame($gemma->id, $account->fresh()->tsa_id);
    }

    public function test_a_tsa_cannot_link_an_account(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $tsaUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        $account = User::factory()->create(['role' => 'normal', 'tsa_id' => null]);

        $this->actingAs($tsaUser)
            ->post(route('calls.tsa-management.link-user', $gemma), ['user_id' => $account->id])
            ->assertForbidden();
    }

    public function test_linking_an_already_linked_tsa_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        User::factory()->create(['role' => 'normal', 'tsa_id' => $gemma->id]);
        $another = User::factory()->create(['role' => 'normal', 'tsa_id' => null]);

        $this->post(route('calls.tsa-management.link-user', $gemma), ['user_id' => $another->id])
            ->assertStatus(409);

        $this->assertNull($another->fresh()->tsa_id);
    }

    public function test_linking_an_admin_account_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $anAdmin = User::factory()->create(['role' => 'admin', 'tsa_id' => null]);

        // 404, not a silent link — the eligible-account query itself
        // excludes anything that isn't role='normal', so an admin's id
        // simply doesn't resolve, same as any other invalid id would.
        $this->post(route('calls.tsa-management.link-user', $gemma), ['user_id' => $anAdmin->id])
            ->assertStatus(404);

        $this->assertNull($anAdmin->fresh()->tsa_id);
    }

    public function test_linking_an_already_linked_account_to_a_second_tsa_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $account = User::factory()->create(['role' => 'normal', 'tsa_id' => $mariel->id]);

        $this->post(route('calls.tsa-management.link-user', $gemma), ['user_id' => $account->id])
            ->assertStatus(404);

        $this->assertSame($mariel->id, $account->fresh()->tsa_id);
    }

    public function test_unlinking_clears_the_tsa_id_but_leaves_the_account_otherwise_untouched(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $account = User::factory()->create(['role' => 'normal', 'tsa_id' => $gemma->id, 'is_active' => true]);

        $this->post(route('calls.tsa-management.unlink-user', $gemma));

        $account->refresh();
        $this->assertNull($account->tsa_id);
        $this->assertSame('normal', $account->role);
        $this->assertTrue($account->is_active);
    }

    public function test_a_tsa_cannot_unlink_an_account(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $tsaUser = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);
        User::factory()->create(['role' => 'normal', 'tsa_id' => $gemma->id]);

        $this->actingAs($tsaUser)
            ->post(route('calls.tsa-management.unlink-user', $gemma))
            ->assertForbidden();
    }

    public function test_a_deactivated_tsa_login_cannot_sign_in(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create([
            'role' => 'tsa', 'tsa_id' => $gemma->id, 'is_active' => false,
            'password' => bcrypt('password'),
        ]);

        $response = $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Explicit request (2026-08-24): the manual Active checkbox is gone —
     *  the table shows each TSA's real live status instead, which already
     *  conveys whether they're actually working. A save here must never be
     *  able to silently deactivate someone the way it would have if this
     *  still read $request->boolean('active') against a form with no such
     *  field left to submit. */
    public function test_saving_an_update_never_changes_active(): void
    {
        $this->actingAs($this->admin());

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $this->assertTrue($gemma->active);

        $this->post(route('calls.tsa-management.update', $gemma), [
            'products' => $gemma->products()->pluck('products.id')->all(),
        ]);

        $this->assertTrue($gemma->fresh()->active);
    }

    public function test_a_tsa_cannot_add_a_new_tsa_or_search_pos_users(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::factory()->create(['role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->post(route('calls.tsa-management.store'), ['display_name' => 'New Person', 'team' => 'SH Naturals'])->assertForbidden();
        $this->actingAs($user)->getJson(route('calls.tsa-management.pos-users'))->assertForbidden();
    }

    public function test_adding_a_tsa_creates_it_active_with_no_products_assigned(): void
    {
        $this->actingAs($this->admin());

        $response = $this->post(route('calls.tsa-management.store'), [
            'display_name' => 'Ana Cruz',
            'team'         => 'SH Naturals',
            'pos_user_id'  => 'pos-123',
        ]);

        $response->assertRedirect(route('calls.tsa-management'));
        $tsa = TsaShift::where('display_name', 'Ana Cruz')->first();
        $this->assertNotNull($tsa);
        $this->assertSame('Ana', $tsa->tsa_key);
        $this->assertSame('SH Naturals', $tsa->team);
        $this->assertSame('pos-123', $tsa->pos_user_id);
        $this->assertTrue($tsa->active);
        $this->assertCount(0, $tsa->products);
    }

    public function test_adding_a_tsa_with_a_duplicate_first_name_gets_a_unique_key(): void
    {
        $this->actingAs($this->admin());
        // "Gemma" (tsa_key) already exists in the seeded roster.
        $this->post(route('calls.tsa-management.store'), ['display_name' => 'Gemma Reyes', 'team' => 'SH Naturals']);

        $tsa = TsaShift::where('display_name', 'Gemma Reyes')->first();
        $this->assertSame('Gemma2', $tsa->tsa_key);
    }

    public function test_adding_a_tsa_rejects_an_invalid_team(): void
    {
        $this->actingAs($this->admin());

        $response = $this->post(route('calls.tsa-management.store'), ['display_name' => 'Someone', 'team' => 'Not A Real Team']);

        $response->assertSessionHasErrors('team');
        $this->assertDatabaseMissing('tsa_shifts', ['display_name' => 'Someone']);
    }

    public function test_search_pos_users_filters_by_query_and_excludes_api_connection_rows(): void
    {
        $this->actingAs($this->admin());
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/users*' => Http::response(['data' => [
                ['id' => 'u1', 'user' => ['name' => 'Ana Cruz']],
                ['id' => 'u2', 'user' => ['name' => 'Ben Santos']],
                ['id' => 'u3', 'user' => ['name' => 'API_CONNECTION System']],
            ]], 200),
        ]);

        $response = $this->getJson(route('calls.tsa-management.pos-users', ['q' => 'ana']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'Ana Cruz']);
        $response->assertJsonMissing(['name' => 'API_CONNECTION System']);
    }
}
