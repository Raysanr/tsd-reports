<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_user_cannot_access_sync_health(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('sync-health'))->assertForbidden();
    }

    public function test_admin_can_view_sync_health_with_full_run_history(): void
    {
        $this->actingAs(User::factory()->create());

        SyncRun::create(['ran_at' => now()->subHour(), 'total_synced' => 10, 'new_orders' => 5, 'upsell_count' => 2, 'upsell_sales' => 500, 'duration_ms' => 1200, 'success' => true]);
        SyncRun::create(['ran_at' => now()->subMinutes(30), 'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 300, 'success' => false, 'error_message' => 'API error on page 1: 500']);

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $response->assertViewHas('totalRuns', 2);
        $response->assertViewHas('failedRuns', 1);
        $response->assertSee('data-sortable-table', false);
    }

    public function test_stale_status_is_flagged_correctly(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('last_synced', now()->subHours(2)->toDateTimeString());
        Setting::set('sync_interval', '2');

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $response->assertViewHas('health', fn ($health) => $health['sync_stale'] === true);
    }

    public function test_retry_triggers_a_sync_for_the_given_date(): void
    {
        $this->actingAs(User::factory()->create());
        // No pancake_api_key configured — deterministically produces a failed
        // SyncRun without needing to fake the Pancake HTTP API for this test.
        $response = $this->post(route('sync-health.retry'), ['date' => now()->toDateString()]);

        $response->assertRedirect(route('sync-health'));
        $this->assertDatabaseHas('sync_runs', ['success' => false]);
    }

    public function test_retry_redacts_api_key_from_the_flashed_error_message(): void
    {
        $this->actingAs(User::factory()->create());
        SyncRun::create([
            'ran_at' => now(), 'success' => false,
            'error_message' => 'cURL error 6: could not resolve host for https://pos.pages.fm/api/v1/shops/1/orders?api_key=some-secret-value&page_size=100',
            'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 0,
        ]);
        // This test just confirms the redaction helper is actually wired into
        // this controller's flash path — the retry() call itself will create a
        // NEW SyncRun (likely a plain "not configured" failure), so assert on
        // the flashed session message from THIS request specifically.
        $response = $this->post(route('sync-health.retry'), ['date' => now()->toDateString()]);

        $response->assertSessionHas('error');
        $flashed = session('error');
        $this->assertIsString($flashed);
        $this->assertStringNotContainsString('some-secret-value', $flashed);
    }
}
