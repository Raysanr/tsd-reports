<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_default_role_is_admin_and_active(): void
    {
        $user = User::factory()->create();

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isAtLeastAdmin());
        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_role_state_factories(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->isSuperAdmin());
        $this->assertSame('normal', User::factory()->normal()->create()->role);
        $this->assertSame('guest', User::factory()->guestRole()->create()->role);
    }

    public function test_can_manage_rules(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin      = User::factory()->admin()->create();
        $normal     = User::factory()->normal()->create();

        $this->assertTrue($superAdmin->canManage($admin));
        $this->assertTrue($superAdmin->canManage($normal));
        $this->assertFalse($superAdmin->canManage($superAdmin), 'no self-management');

        $this->assertTrue($admin->canManage($normal));
        $this->assertFalse($admin->canManage($superAdmin), 'admin cannot manage super admin');
        $this->assertFalse($admin->canManage($admin), 'no self-management, and admin cannot manage admin anyway');
    }

    public function test_active_super_admin_count(): void
    {
        $this->assertSame(0, User::activeSuperAdminCount());

        $superAdmin = User::factory()->superAdmin()->create();
        $this->assertSame(1, User::activeSuperAdminCount());

        $superAdmin->update(['is_active' => false]);
        $this->assertSame(0, User::activeSuperAdminCount());
    }
}
