<?php

namespace Tests\Feature;

use App\Models\ReconciliationRun;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_user_cannot_access_reconciliation_page(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('reconciliation'))->assertForbidden();
    }

    public function test_admin_can_view_reconciliation_history(): void
    {
        $this->actingAs(User::factory()->create());

        ReconciliationRun::create([
            'ran_at' => now()->subDay(), 'checked_date' => now()->subDays(2)->toDateString(),
            'local_count' => 40, 'pancake_count' => 50, 'issues' => ['Completeness: something is off'],
            'issue_count' => 1, 'has_issues' => true,
        ]);
        ReconciliationRun::create([
            'ran_at' => now(), 'checked_date' => now()->subDay()->toDateString(),
            'local_count' => 50, 'pancake_count' => 50, 'issues' => [],
            'issue_count' => 0, 'has_issues' => false,
        ]);

        $response = $this->get(route('reconciliation'));

        $response->assertOk();
        $response->assertViewHas('totalRuns', 2);
        $response->assertViewHas('runsWithIssues', 1);
        $response->assertSee('data-sortable-table', false);
        $response->assertSee('Completeness: something is off');
    }

    public function test_reconciliation_history_page_shows_no_data_state_when_no_runs_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('reconciliation'));

        $response->assertOk();
        $response->assertSee('No reconciliation runs recorded yet');
    }

    public function test_admin_can_drill_into_a_single_reconciliation_run(): void
    {
        $this->actingAs(User::factory()->create());

        $run = ReconciliationRun::create([
            'ran_at' => now(), 'checked_date' => now()->subDay()->toDateString(),
            'local_count' => 40, 'pancake_count' => 50,
            'issues' => ['Completeness: Pancake reports 50 orders touched on 2026-07-19, but only 40 are synced locally — sync may have missed a window that day.'],
            'issue_count' => 1, 'has_issues' => true,
        ]);

        $response = $this->get(route('reconciliation.show', $run));

        $response->assertOk();
        $response->assertSee('40');
        $response->assertSee('50');
        $response->assertSee('sync may have missed a window that day', false);
    }

    public function test_normal_role_user_cannot_access_reconciliation_show(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $run = ReconciliationRun::create([
            'ran_at' => now(), 'issues' => [], 'issue_count' => 0, 'has_issues' => false,
        ]);

        $this->get(route('reconciliation.show', $run))->assertForbidden();
    }

    public function test_dashboard_reconciliation_banner_links_to_the_reconciliation_page(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('reconciliation_issues', json_encode(['Some stale issue']));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('reconciliation'), false);
    }
}
