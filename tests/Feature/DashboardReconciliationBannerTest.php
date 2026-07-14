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

    public function test_dashboard_shows_no_banner_when_the_setting_was_never_written(): void
    {
        $this->actingAs(User::factory()->create());

        // pancake:reconcile has never run — Setting::get() returns null, not '[]'.

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Tag drift');
        $response->assertDontSee('Completeness:');
    }
}
