<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Hub's TSA / Product / User Management pages render the SAME views as
 * the main dashboard pages (explicit request, 2026-10-20: the Hub copy had
 * fallen behind — no eye/hide toggle — so the copies were removed).
 */
class HubManagementSharedViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_pages_render_the_main_views_inside_the_hub_frame(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['hub.tsa-management' => 'TSA Management', 'hub.product-management' => 'Product Management', 'hub.users' => 'User Management'] as $route => $title) {
            $this->get(route($route))->assertOk()
                ->assertSee($title)
                ->assertSee('data-manage-menu', false)   // Hub frame
                ->assertDontSee('id="sidebar"', false);  // not the dashboard frame
        }
    }

    public function test_hub_tsa_page_has_the_eye_toggle_just_like_the_main_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $shift = TsaShift::create(['tsa_key' => 'EYE', 'display_name' => 'Eye Test', 'team' => 'SH Naturals', 'active' => true]);
        $toggle = route('tsa-management.toggle-hidden', $shift);

        $this->get(route('hub.tsa-management'))->assertSee($toggle, false)->assertSee('toggleHiddenTsaBtn', false);
        $this->get(route('hub.tsa-management'))->assertSee($toggle, false);
    }

    public function test_actions_return_to_whichever_frame_the_page_was_opened_from(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $shift = TsaShift::create(['tsa_key' => 'GONE', 'display_name' => 'Gone Soon', 'team' => 'SH Naturals', 'active' => true]);

        $this->get(route('hub.tsa-management'));
        $this->delete(route('tsa-management.destroy', $shift))->assertRedirect(route('hub.tsa-management'));

        $this->get(route('hub.tsa-management'));
        $this->post(route('tsa-management.restore', $shift->id))->assertRedirect(route('hub.tsa-management'));
    }
}
