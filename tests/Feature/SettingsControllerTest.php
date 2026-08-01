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

    /**
     * Explicit request: the Pancake API key (and, below, the Drive client
     * secret/refresh token) used to be echoed back into the page in full
     * plaintext on every load — masked visually by type="password", but the
     * real value still sat in the raw HTML, readable via View Source/DevTools
     * by anyone with access to that response. index() must never render the
     * real value anywhere now, only the masked last-4-chars form.
     */
    public function test_index_never_renders_the_real_api_key_or_drive_secrets(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'super-secret-pancake-key-9999');
        Setting::set('drive_client_secret', 'super-secret-drive-client-8888');
        Setting::set('drive_refresh_token', 'super-secret-drive-refresh-7777');

        $response = $this->get(route('settings'));

        $response->assertOk();
        $response->assertDontSee('super-secret-pancake-key-9999');
        $response->assertDontSee('super-secret-drive-client-8888');
        $response->assertDontSee('super-secret-drive-refresh-7777');
        // The masked last-4 form is expected to still appear.
        $response->assertSee('9999');
        $response->assertSee('8888');
        $response->assertSee('7777');
    }

    public function test_save_with_a_blank_api_key_keeps_the_existing_key_and_never_calls_pancake(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('pancake_api_key', 'the-real-working-key');
        Setting::set('shop_id', '30037101');
        Setting::set('shop_name', 'My Shop');

        // No Http::fake stub registered at all — a real call to Pancake here
        // would throw "no matching fake" and fail the test, proving the
        // verification step was correctly skipped for an unchanged key.
        Http::fake();

        $response = $this->post(route('settings.save'), [
            'api_key'       => '',
            'sync_interval' => '15',
        ]);

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
        $this->assertSame('the-real-working-key', Setting::get('pancake_api_key'));
        $this->assertSame('30037101', Setting::get('shop_id'));
        $this->assertSame(15, (int) Setting::get('sync_interval'));
        Http::assertNothingSent();
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

    private function driveFormData(): array
    {
        return [
            'drive_client_id'          => 'client-id-123',
            'drive_client_secret'      => 'client-secret-abc',
            'drive_refresh_token'      => 'refresh-token-xyz',
            'drive_folder_sh_naturals' => 'folder-sh-naturals',
            'drive_folder_eyecare'     => 'folder-eyecare',
        ];
    }

    public function test_drive_save_rejects_credentials_that_fail_verification_and_does_not_persist_them(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $response = $this->post(route('settings.drive.save'), $this->driveFormData());

        $response->assertSessionHasErrors('drive_refresh_token');
        $this->assertSame('', Setting::get('drive_refresh_token', ''));
    }

    public function test_drive_save_persists_credentials_when_the_token_verifies(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a-real-access-token'], 200),
        ]);

        $response = $this->post(route('settings.drive.save'), $this->driveFormData());

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
        $this->assertSame('refresh-token-xyz', Setting::get('drive_refresh_token'));
        $this->assertSame('folder-sh-naturals', Setting::get('drive_folder_sh_naturals'));
        $this->assertSame('folder-eyecare', Setting::get('drive_folder_eyecare'));
    }

    public function test_drive_save_with_blank_secret_and_token_keeps_the_existing_ones(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_client_secret', 'the-real-client-secret');
        Setting::set('drive_refresh_token', 'the-real-refresh-token');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a-real-access-token'], 200),
        ]);

        $response = $this->post(route('settings.drive.save'), [
            'drive_client_id'          => 'new-client-id',
            'drive_client_secret'      => '', // left blank — keep existing
            'drive_refresh_token'      => '', // left blank — keep existing
            'drive_folder_sh_naturals' => 'new-folder-sh',
            'drive_folder_eyecare'     => 'new-folder-eyecare',
        ]);

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
        $this->assertSame('the-real-client-secret', Setting::get('drive_client_secret'));
        $this->assertSame('the-real-refresh-token', Setting::get('drive_refresh_token'));
        $this->assertSame('new-client-id', Setting::get('drive_client_id'));
        $this->assertSame('new-folder-sh', Setting::get('drive_folder_sh_naturals'));

        // Verification still ran, using the EXISTING secret/token together
        // with the newly-submitted client id — not skipped outright, since
        // the client id (a non-secret) did genuinely change.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['client_id'] === 'new-client-id'
                && $request['client_secret'] === 'the-real-client-secret'
                && $request['refresh_token'] === 'the-real-refresh-token';
        });
    }

    public function test_drive_clear_wipes_all_stored_drive_credentials(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->driveFormData() as $key => $value) {
            Setting::set($key, $value);
        }

        $response = $this->post(route('settings.drive.clear'));

        $response->assertRedirect(route('settings'));
        foreach (array_keys($this->driveFormData()) as $key) {
            $this->assertSame('', Setting::get($key, ''));
        }
    }

    public function test_sync_now_refuses_to_run_when_not_connected(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('settings.drive.sync-now'));

        $response->assertSessionHasErrors('drive_refresh_token');
    }

    public function test_sync_now_starts_a_background_sync_when_connected(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_refresh_token', 'refresh-token-xyz');

        $response = $this->post(route('settings.drive.sync-now'));

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success');
    }

    public function test_sync_now_refuses_to_start_a_second_sync_while_one_is_already_running(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_refresh_token', 'refresh-token-xyz');
        Setting::set('drive_sync_running', '1');

        $response = $this->post(route('settings.drive.sync-now'));

        $response->assertSessionHasErrors('drive_refresh_token');
    }

    /**
     * Confirmed in production: a TSA's phone can upload a call recording to
     * Drive after the last scheduled sync run for that day already happened —
     * since every run only ever looked at "today", that recording's hour was
     * stuck showing the flat 3-min/call AHT estimate forever, with no way to
     * go back and pick it up. This is what lets a specific past date be
     * re-checked instead of always re-syncing "today".
     */
    public function test_sync_now_uses_the_submitted_date_not_always_today(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_refresh_token', 'refresh-token-xyz');

        $response = $this->post(route('settings.drive.sync-now'), ['date' => '2026-07-25']);

        $response->assertRedirect(route('settings'));
        $response->assertSessionHas('success', fn ($message) => str_contains($message, '2026-07-25'));
    }

    public function test_sync_now_rejects_a_future_date(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('drive_refresh_token', 'refresh-token-xyz');

        $response = $this->post(route('settings.drive.sync-now'), ['date' => now('Asia/Manila')->addDay()->toDateString()]);

        $response->assertSessionHasErrors('date');
    }
}
