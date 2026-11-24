<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The login page uses the last signed-in person's Appearance accent (2026-11-24). */
class LoginAccentMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hub_remembers_a_custom_accent_for_the_login_page(): void
    {
        $this->actingAs(User::factory()->create(['theme_accent' => '#2442B8']));

        $this->get(route('hub'))->assertOk()
            ->assertSee("localStorage.setItem('tsdLoginAccent','#2442B8')", false);
    }

    public function test_no_custom_accent_forgets_it_so_the_login_stays_gold(): void
    {
        $this->actingAs(User::factory()->create(['theme_accent' => null]));

        $this->get(route('hub'))->assertOk()
            ->assertSee("localStorage.removeItem('tsdLoginAccent')", false);
    }

    public function test_the_login_page_reads_the_remembered_accent(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee("localStorage.getItem('tsdLoginAccent')", false);
    }

    public function test_the_login_page_has_a_light_dark_switch_sharing_the_apps_saved_theme(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('id="themeToggle"', false)
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee("localStorage.setItem('theme'", false);
    }

}
