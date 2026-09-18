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

    /**
     * Regression fix, 2026-09-18 (two rounds): this used to assert on the
     * bare "document.addEventListener('DOMContentLoaded'" string, which
     * also matches partials/messages-panel.blade.php's own, entirely
     * unrelated DOMContentLoaded listener (button wiring for the topbar
     * messaging panel, included on every page) — a false failure, not a
     * real regression in either feature. A first fix narrowed to
     * "window.showToast(" instead, but that ALSO collides — the messaging
     * panel's own sendMessage() error handler calls
     * window.showToast?.('Could not send…', 'error') on a failed send,
     * unconditionally present in the page's JS regardless of session
     * flash state. Narrowed again to the toast bootstrap script's own
     * genuinely unique opening line — layouts/app.blade.php's version
     * uses a plain `function () {` callback specifically (not an arrow
     * function), which nothing else on the page happens to share.
     */
    public function test_no_bootstrap_script_is_rendered_when_nothing_is_flashed(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee("document.addEventListener('DOMContentLoaded', function ()", false);
    }
}
