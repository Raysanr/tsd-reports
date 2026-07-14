<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_reports_success_for_a_working_key(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'shops' => [
                    ['id' => 30037101, 'name' => 'My Shop'],
                ],
            ], 200),
        ]);

        $response = $this->postJson(route('settings.detect'), ['api_key' => 'a-working-key']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'shops'   => [['id' => '30037101', 'name' => 'My Shop']],
        ]);
    }

    public function test_detect_reports_failure_for_a_rejected_key(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'success' => false,
                'message' => 'api_key is invalid',
            ], 403),
        ]);

        $response = $this->postJson(route('settings.detect'), ['api_key' => 'test-key']);

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }
}
