<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Explicit request (2026-08-12): a User Management entry point living in
 * the Hub itself (Hub-styled standalone page, not layouts.app's internal
 * dashboard chrome), reusing UserManagementController's existing data and
 * permission rules rather than a second parallel implementation — the old
 * /user-management page stays as-is, not removed. See that controller's
 * RETURN_ROUTES/redirectToCaller() for how store()/update()/toggleActive()
 * (shared by both pages) know which one to send the browser back to.
 */
class HubUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_user_cannot_reach_the_hubs_user_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']));

        $this->get(route('hub.users'))->assertForbidden();
    }

    public function test_an_admin_can_view_the_hubs_user_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'normal', 'name' => 'Hub Listed User']);

        $response = $this->get(route('hub.users'));

        $response->assertOk();
        $response->assertSee('Hub Listed User');
    }

    public function test_creating_a_user_from_the_hub_page_redirects_back_to_the_hub_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post(route('hub.users.store'), [
            'name' => 'New Hub Person', 'email' => 'new.hub.person@example.com',
            'role' => 'normal', '_redirect_route' => 'hub.users',
        ]);

        $response->assertRedirect(route('hub.users'));
        $this->assertDatabaseHas('users', ['email' => 'new.hub.person@example.com', 'role' => 'normal']);
    }

    /** Same store() endpoint the old page's form already posts to — proves
     *  the default (no _redirect_route, as every request before this
     *  feature existed sent) still lands on the original page, not a
     *  behavior change for that existing form. */
    public function test_creating_a_user_without_a_redirect_route_defaults_to_the_old_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post(route('user-management.store'), [
            'name' => 'Default Route Person', 'email' => 'default.route@example.com', 'role' => 'normal',
        ]);

        $response->assertRedirect(route('user-management'));
    }

    /** An arbitrary route name in _redirect_route must not be trusted as a
     *  raw redirect target — allowlisted against RETURN_ROUTES. */
    public function test_an_unrecognized_redirect_route_falls_back_to_the_old_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post(route('hub.users.store'), [
            'name' => 'Unknown Route Person', 'email' => 'unknown.route@example.com',
            'role' => 'normal', '_redirect_route' => 'dashboard',
        ]);

        $response->assertRedirect(route('user-management'));
    }

    public function test_toggling_active_from_the_hub_page_redirects_back_to_the_hub_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'normal']);

        $response = $this->patch(route('hub.users.toggle-active', $target), [
            '_redirect_route' => 'hub.users',
        ]);

        $response->assertRedirect(route('hub.users'));
        $this->assertFalse($target->refresh()->is_active);
    }

    public function test_deleting_a_user_from_the_hub_page_redirects_back_to_the_hub_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'normal']);

        $response = $this->delete(route('hub.users.destroy', $target), [
            '_redirect_route' => 'hub.users',
        ]);

        $response->assertRedirect(route('hub.users'));
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_hub_page_shows_a_user_management_link_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get(route('hub'));

        $response->assertSee(route('hub.users'), false);
    }

    public function test_hub_page_hides_the_user_management_link_for_a_normal_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'normal']));

        $response = $this->get(route('hub'));

        $response->assertDontSee(route('hub.users'), false);
    }
}
