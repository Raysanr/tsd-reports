<?php

namespace Tests\Feature;

use App\Models\Setting;
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

    public function test_detect_reports_failure_when_response_body_reports_failure(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'success' => false,
                'message' => 'api_key is invalid',
            ], 200),
        ]);

        $response = $this->postJson(route('settings.detect'), ['api_key' => 'test-key']);

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_save_rejects_a_key_that_fails_verification_and_does_not_persist_it(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'the-real-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'success' => false,
                'message' => 'api_key is invalid',
            ], 403),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key' => 'test-key',
            'shop_id' => '30037101',
        ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertSame('the-real-working-key', Setting::get('pancake_api_key'));
    }

    public function test_save_rejects_when_the_verified_shop_does_not_match_the_submitted_shop_id(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'the-real-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'shops' => [
                    ['id' => 99999999, 'name' => 'A Different Shop'],
                ],
            ], 200),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key' => 'a-key-for-a-different-shop',
            'shop_id' => '30037101',
        ]);

        $response->assertSessionHasErrors('api_key');
        $this->assertSame('the-real-working-key', Setting::get('pancake_api_key'));
    }

    public function test_save_persists_settings_when_the_key_verifies_and_matches(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'pos.pages.fm/api/v1/shops*' => Http::response([
                'shops' => [
                    ['id' => 30037101, 'name' => 'My Shop'],
                ],
            ], 200),
        ]);

        $response = $this->post(route('settings.save'), [
            'api_key'       => 'a-working-key',
            'shop_id'       => '30037101',
            'shop_name'     => 'My Shop',
            'sync_interval' => '5',
        ]);

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
        $this->assertSame('a-working-key', Setting::get('pancake_api_key'));
        $this->assertSame('30037101', Setting::get('shop_id'));
        $this->assertSame(5, (int) Setting::get('sync_interval'));
    }
}
