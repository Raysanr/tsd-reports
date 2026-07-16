<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToastReplacesFlashBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, array{0: string}> */
    public static function pageRouteProvider(): array
    {
        return [
            'product-management' => ['product-management'],
            'tsa-management'     => ['tsa-management'],
            'user-management'    => ['user-management'],
            'settings'           => ['settings'],
        ];
    }

    /** @dataProvider pageRouteProvider */
    public function test_page_no_longer_renders_its_own_inline_success_banner(string $routeName): void
    {
        session()->flash('success', 'Saved.');

        $response = $this->get(route($routeName));

        $response->assertOk();
        // The old banner's exact wrapper classes — if this string is gone,
        // the inline block was removed (the toast bootstrap script in the
        // layout, covered by ToastContainerRenderingTest, is what renders the
        // message now instead).
        $response->assertDontSee('bg-green-50 border border-green-200 rounded-xl px-5 py-4', false);
        $response->assertSee('window.showToast(', false);
    }
}
