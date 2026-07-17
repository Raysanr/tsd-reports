<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DarkModeToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_the_theme_toggle_button(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="themeToggle"', false);
    }

    public function test_dashboard_renders_the_no_flash_inline_theme_script_before_any_stylesheet(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));
        $html = $response->getContent();

        $scriptPos = strpos($html, "localStorage.getItem('theme')");
        $linkPos   = strpos($html, '<link');

        $response->assertOk();
        $this->assertNotFalse($scriptPos, 'Expected the no-flash inline theme script to be present.');
        $this->assertNotFalse($linkPos, 'Expected at least one <link> (stylesheet) tag to exist for comparison.');
        $this->assertLessThan($linkPos, $scriptPos, 'Theme script must run before any stylesheet link to avoid a flash of the wrong theme.');
    }
}
