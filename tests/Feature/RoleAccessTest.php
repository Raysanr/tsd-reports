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
