<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToastContainerRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_page_renders_the_toast_container(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="toastContainer"', false);
        $response->assertSee('class="fixed top-4 right-4', false);
    }

    public function test_a_flashed_success_message_is_rendered_as_a_toast_call(): void
    {
        $this->actingAs(User::factory()->create());
        session()->flash('success', 'Test flash message.');

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('window.showToast(', false);
        $response->assertSee('Test flash message.', false);
        $response->assertSee("'success'", false);
    }

    public function test_no_bootstrap_script_is_rendered_when_nothing_is_flashed(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('window.showToast(', false);
    }
}
