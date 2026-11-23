<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI/UX review finding: hitting a 403/404 fell through to Symfony's bare,
 * unbranded default error page — jarringly inconsistent with the rest of
 * the app's polish, and unhelpful (no way back, no explanation). Custom
 * views under resources/views/errors/ fix this automatically since
 * Laravel's exception handler renders errors.{status} when it exists.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_page_is_branded_not_the_bare_default(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $response = $this->get(route('hub.product-management'));

        $response->assertForbidden();
        $response->assertSee('TSD Reports Hub', false);
        $response->assertSee('Access denied', false);
        $response->assertSee(route('hub'), false);
    }

    public function test_404_page_is_branded_not_the_bare_default(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/this-route-does-not-exist-anywhere');

        $response->assertNotFound();
        $response->assertSee('TSD Reports Hub', false);
        $response->assertSee('Page not found', false);
    }

    public function test_429_and_500_pages_use_the_same_design(): void
    {
        foreach (['429' => 'Slow down a moment', '500' => 'Something went wrong'] as $code => $title) {
            $html = view("errors.{$code}")->render();
            $this->assertStringContainsString($title, $html);
            $this->assertStringContainsString('TSD Reports Hub', $html);
            $this->assertStringContainsString(route('hub'), $html);
        }
    }
}
