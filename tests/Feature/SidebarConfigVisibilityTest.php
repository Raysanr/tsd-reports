<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarConfigVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** TSA / Product / User Management live only in the Hub (2026-11-17). */
    public function test_admin_sees_config_section_without_the_hub_management_links(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Config');
        $response->assertSee('Sync Health');
        $response->assertDontSee('href="' . route('tsa-management') . '"', false);
        $response->assertDontSee('href="' . route('product-management') . '"', false);
        $response->assertDontSee('href="' . route('user-management') . '"', false);
    }

    public function test_old_management_urls_open_the_hub_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('tsa-management', ['month' => '2026-11']))->assertRedirect(route('hub.tsa-management', ['month' => '2026-11']));
        $this->get(route('product-management'))->assertRedirect(route('hub.product-management'));
        $this->get(route('user-management'))->assertRedirect(route('hub.users'));
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
