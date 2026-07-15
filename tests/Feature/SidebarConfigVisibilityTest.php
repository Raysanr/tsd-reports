<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarConfigVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_config_section_and_user_management_link(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('User Management');
        $response->assertSee('TSA Management');
    }

    public function test_normal_user_does_not_see_config_section(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('User Management');
        $response->assertDontSee('TSA Management');
        $response->assertDontSee('Product Management');
    }
}
