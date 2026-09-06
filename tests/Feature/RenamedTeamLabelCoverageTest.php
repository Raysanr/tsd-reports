<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug fix (2026-09-06) — an audit triggered by the user noticing a raw
 * "SH NATURALS" label on the Dashboard found 4 real spots across the app
 * that still printed a team's fixed, never-renamed order_team string
 * directly instead of resolving it through App\Support\Teams the way
 * every other team-grouped page already does (e.g.
 * round-robin-setup/_table.blade.php, tsa-management.blade.php's own
 * active roster). This locks in the fix for all 4.
 */
class RenamedTeamLabelCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function renameShNaturals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('settings.team-names'), [
            'team_names' => ['sh-naturals' => 'Team Closing'],
        ]);
    }

    public function test_monitor_tsa_shows_the_renamed_team_label_not_the_raw_order_team(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.monitor'));

        $response->assertOk();
        $response->assertSee('Team Closing');
        $response->assertDontSee('SH Naturals');
    }

    public function test_monitor_tsa_csv_export_uses_the_renamed_team_label(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.monitor.export'));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Team Closing', $csv);
        $this->assertStringNotContainsString('SH Naturals', $csv);
    }

    public function test_tsa_management_trashed_panel_shows_the_renamed_team_label(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->delete();

        $response = $this->actingAs($admin)->get(route('tsa-management'));

        $response->assertOk();
        // Scoped to the "Removed" row's own visible text (not a whole-page
        // assertDontSee) — a legitimate data-team="SH Naturals" JS hook
        // still exists elsewhere on this page (the active roster's own
        // edit-form attributes) and must not be confused with a label.
        $response->assertSee('Team Closing — removed', false);
    }

    public function test_hub_tsa_management_trashed_panel_shows_the_renamed_team_label(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->delete();

        $response = $this->actingAs($admin)->get(route('hub.tsa-management'));

        $response->assertOk();
        // The "Removed" row's own visible text must show the resolved name —
        // scoped to that exact text (not a whole-page assertDontSee) since a
        // legitimate data-team="SH Naturals" JS hook still exists elsewhere
        // on this page (the active roster's own edit-form attributes) and
        // must NOT be confused with a visible label.
        $response->assertSee('Team Closing — removed', false);
    }

    public function test_adding_a_tsa_logs_the_renamed_team_name_not_the_raw_order_team(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('tsa-management.store'), [
            'display_name' => 'New Person', 'team' => 'SH Naturals',
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'tsa.created']);
        $log = \App\Models\ActivityLog::where('action', 'tsa.created')->latest()->first();
        $this->assertStringContainsString('Team Closing', $log->description);
        $this->assertStringNotContainsString('to SH Naturals', $log->description);
    }

    public function test_call_tracker_adding_a_tsa_logs_the_renamed_team_name(): void
    {
        $this->renameShNaturals();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('calls.tsa-management.store'), [
            'display_name' => 'New Person', 'team' => 'SH Naturals',
        ]);

        $log = \App\Models\ActivityLog::where('action', 'tsa.created')->latest()->first();
        $this->assertStringContainsString('Team Closing', $log->description);
        $this->assertStringNotContainsString('to SH Naturals', $log->description);
    }
}
