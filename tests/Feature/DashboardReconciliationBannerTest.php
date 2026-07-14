<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReconciliationBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_no_banner_when_there_are_no_reconciliation_issues(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('reconciliation_issues', json_encode([]));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Tag drift');
        $response->assertDontSee('Completeness');
    }

    public function test_dashboard_shows_a_banner_when_reconciliation_found_issues(): void
    {
        $this->actingAs(User::factory()->create());

        Setting::set('reconciliation_issues', json_encode([
            'Completeness: Pancake reports 50 orders touched on 2026-07-13, but only 2 are synced locally — sync may have missed a window that day.',
        ]));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Completeness', false);
        $response->assertSee('sync may have missed a window', false);
    }

    public function test_dashboard_shows_no_banner_when_the_setting_row_has_a_null_value(): void
    {
        $this->actingAs(User::factory()->create());

        // Setting::get() only ever returns raw null when a row exists with value
        // IS NULL — a missing row instead falls back to the '[]' default passed at
        // the DashboardController call site, so it behaves identically to the "no
        // issues" test above. Setting::set() always writes a JSON-encoded string
        // and can never produce this state; the `value` column is nullable though,
        // so it's reachable via a manual DB edit or a future write path. Insert the
        // row directly to exercise DashboardController's `?: []` fallback against a
        // real json_decode(null, true) === null.
        Setting::query()->create(['key' => 'reconciliation_issues', 'value' => null]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Tag drift');
        $response->assertDontSee('Completeness:');

        // The HTML assertions above pass even without the `?: []` fallback, because
        // the view guards with `@if(!empty($reconciliationIssues))` and empty(null)
        // is also true — so they can't actually prove the fallback matters. Assert
        // directly on the data handed to the view instead: without `?: []` this
        // would be null (json_decode(null, true) === null), not [].
        $response->assertViewHas('reconciliationIssues', []);
    }
}
