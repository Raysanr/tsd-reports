<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_reach_user_management(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('user-management'))->assertForbidden();
    }

    public function test_admin_can_view_the_user_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        User::factory()->normal()->create(['name' => 'Regular Person']);

        $response = $this->get(route('user-management'));

        $response->assertOk();
        $response->assertSee('Regular Person');
    }

    public function test_admin_can_create_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'New Person',
            'email' => 'new.person@example.com',
            'role'  => 'normal',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', [
            'email'     => 'new.person@example.com',
            'role'      => 'normal',
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_create_an_admin_account(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'Sneaky',
            'email' => 'sneaky@example.com',
            'role'  => 'admin',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_super_admin_can_create_an_admin_account(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $response = $this->post(route('user-management.store'), [
            'name'  => 'New Admin',
            'email' => 'new.admin@example.com',
            'role'  => 'admin',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['email' => 'new.admin@example.com', 'role' => 'admin']);
    }

    public function test_admin_can_update_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();

        $response = $this->put(route('user-management.update', $target), [
            'name'  => 'Renamed Person',
            'email' => $target->email,
            'role'  => 'guest',
        ]);

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed Person', 'role' => 'guest']);
    }

    public function test_admin_cannot_update_another_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->admin()->create();

        $response = $this->put(route('user-management.update', $target), [
            'name'  => 'Should Not Apply',
            'email' => $target->email,
            'role'  => 'normal',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['id' => $target->id, 'name' => 'Should Not Apply']);
    }

    public function test_user_cannot_edit_their_own_row(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);

        $response = $this->put(route('user-management.update', $actor), [
            'name'  => 'Self Edit',
            'email' => $actor->email,
            'role'  => 'admin',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_deactivate_a_normal_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();
        $this->assertTrue($target->is_active);

        $response = $this->patch(route('user-management.toggle-active', $target));

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_toggle_active_twice_reactivates(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->normal()->create();

        $this->patch(route('user-management.toggle-active', $target));
        $this->patch(route('user-management.toggle-active', $target));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_admin_cannot_deactivate_another_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $target = User::factory()->admin()->create();

        $response = $this->patch(route('user-management.toggle-active', $target));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_deactivating_one_of_two_super_admins_leaves_one_active(): void
    {
        // No explicit "last active Super Admin" runtime guard exists — it isn't
        // needed. Self-edit is blocked, and only a Super Admin can manage another
        // Super Admin, so reaching this controller with a Super Admin target
        // requires the actor to already be a SECOND, distinct, active Super
        // Admin — meaning at least one always remains after the mutation. This
        // test documents that invariant with the simplest case that exercises it
        // (two Super Admins, one deactivates the other).
        $actingSuperAdmin = User::factory()->superAdmin()->create();
        $targetSuperAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($actingSuperAdmin);

        $response = $this->patch(route('user-management.toggle-active', $targetSuperAdmin));

        $response->assertRedirect(route('user-management'));
        $this->assertDatabaseHas('users', ['id' => $targetSuperAdmin->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $actingSuperAdmin->id, 'is_active' => true]);
        $this->assertSame(1, User::activeSuperAdminCount());
    }

    public function test_index_hides_edit_and_deactivate_controls_for_rows_the_viewer_cannot_manage(): void
    {
        // canManage() is enforced server-side in update()/toggleActive() regardless
        // of what the view renders — this test guards the view's @if(canManage())
        // gating specifically, so a future edit to the Blade template can't quietly
        // drift from the controller's own check without a test catching it.
        $actingAdmin = User::factory()->admin()->create();
        $peerAdmin   = User::factory()->admin()->create();
        $normalUser  = User::factory()->normal()->create();
        $this->actingAs($actingAdmin);

        $response = $this->get(route('user-management'));

        $response->assertOk();
        // Neither button renders for a peer Admin (Admin cannot manage Admin)...
        $response->assertDontSee('data-id="' . $peerAdmin->id . '"', false);
        // ...nor for the actor's own row (no self-service editing)...
        $response->assertDontSee('data-id="' . $actingAdmin->id . '"', false);
        // ...but both do render for a Normal user, who the acting Admin can manage.
        $response->assertSee('data-id="' . $normalUser->id . '"', false);
    }
}
