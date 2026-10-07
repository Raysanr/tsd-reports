<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_can_view_main_report_pages(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('leads-report'))->assertOk();
    }

    public function test_normal_user_is_forbidden_from_config_pages(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('tsa-management'))->assertForbidden();
        $this->get(route('product-management'))->assertForbidden();
        $this->get(route('settings'))->assertForbidden();
    }

    public function test_normal_user_cannot_access_bulk_action_routes(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $product = \App\Models\Product::first();
        $tsaShift = \App\Models\TsaShift::first();

        $this->post(route('product-management.bulk'), [
            'ids'    => [$product->id],
            'action' => 'hide',
        ])->assertForbidden();

        $this->post(route('tsa-management.bulk'), [
            'ids'    => [$tsaShift->id],
            'action' => 'delete',
        ])->assertForbidden();
    }

    public function test_guest_role_is_forbidden_from_config_pages(): void
    {
        $this->actingAs(User::factory()->guestRole()->create());

        $this->get(route('tsa-management'))->assertForbidden();
    }

    public function test_admin_can_reach_config_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('tsa-management'))->assertOk();
    }

    /** TSD Data Management opened up to normal-role (TSA) users (explicit
     *  request, 2026-10-07: "make it the data management module is
     *  visible for the TSA's (NORMAL USERS)") — Projections/DSPPR/
     *  Summary Sales Report/Expected Income all became normal-accessible,
     *  EXCEPT Cost Breakdown (real payroll/salary data), which stays
     *  behind its own nested role:super_admin,admin gate (see routes/
     *  web.php's own Data Management group). */
    public function test_normal_user_can_access_four_data_management_pages_but_not_cost_breakdown(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('data.projections'))->assertOk();
        $this->get(route('data.dsppr'))->assertOk();
        $this->get(route('data.tsa-sales'))->assertOk();
        $this->get(route('data.expected-income'))->assertOk();
        $this->get(route('data.cost-breakdown'))->assertForbidden();
    }

    public function test_admin_still_has_full_data_management_access_including_cost_breakdown(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('data.cost-breakdown'))->assertOk();
    }

    /** The Hub's own "TSD Data Management" card (explicit request,
     *  2026-10-07 — see test_normal_user_can_access_four_data_
     *  management_pages_but_not_cost_breakdown()'s own doc comment)
     *  shows for a normal user now too, not just super_admin/admin. */
    public function test_the_data_management_hub_card_shows_for_a_normal_user(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->get(route('hub'));

        $response->assertOk();
        $response->assertSee('TSD Data Management');
    }

    public function test_normal_user_can_trigger_sync(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->post(route('dashboard.sync'));

        // dashboard.sync is a JSON fetch() endpoint (see dashboard.blade.php),
        // not a redirect-back form handler, so a normal/allowed user gets 200;
        // the point of this assertion is that it's not 403.
        $response->assertOk();
    }

    public function test_guest_role_cannot_trigger_sync(): void
    {
        $this->actingAs(User::factory()->guestRole()->create());

        $this->post(route('dashboard.sync'))->assertForbidden();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
