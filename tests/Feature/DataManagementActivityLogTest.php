<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CostBreakdownRole;
use App\Models\ProjectionColumn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data Management's own Activity Log page (explicit request, 2026-10-07:
 * "can you add another page that is ACTIVITY LOG? ... the history of who
 * edited this ... and what she edited ... all activites in every page
 * should be recorded on that"). Reuses the app's existing ActivityLog
 * model (same table the admin-only /audit-log page already reads), just
 * filtered to this module's own action prefixes. See
 * App\Http\Controllers\DataManagement\ActivityLogController and
 * App\Support\ActivityLogger::logFieldUpdate()'s own doc comments.
 */
class DataManagementActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_user_can_view_the_page(): void
    {
        $normal = User::factory()->create(['role' => 'normal']);

        $this->actingAs($normal)->get(route('data.activity-log'))->assertOk();
    }

    public function test_editing_a_projection_column_creates_a_log_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();

        $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['net_income_target' => 12345]
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'projection.field_updated',
        ]);
        $log = ActivityLog::where('action', 'projection.field_updated')->latest('id')->first();
        $this->assertStringContainsString('12,345', $log->description);
        $this->assertStringContainsString($admin->name, $log->actor_name);
    }

    /** The "When" column shows the real date/time, not just a relative
     *  phrase (explicit follow-up, 2026-10-07: "in the activity page i
     *  want you to add time like that") — relative phrasing ("2 hours
     *  ago") stays as a smaller second line, not the only thing shown. */
    public function test_the_page_shows_the_real_timestamp_not_just_a_relative_phrase(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['net_income_target' => 11111]
        )->assertOk();

        $log = ActivityLog::where('action', 'projection.field_updated')->latest('id')->first();

        $response = $this->actingAs($admin)->get(route('data.activity-log'));
        $response->assertOk();
        $response->assertSee($log->created_at->format('M j, Y g:i A'));
    }

    public function test_editing_cost_breakdown_creates_a_log_entry_visible_only_to_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $normal = User::factory()->create(['role' => 'normal']);
        CostBreakdownRole::ensureSeeded();
        $role = CostBreakdownRole::first();

        $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $role),
            ['base_salary' => 99999]
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'cost_breakdown.field_updated']);

        $adminView = $this->actingAs($admin)->get(route('data.activity-log'));
        $adminView->assertOk();
        $adminView->assertSee('99,999');

        $normalView = $this->actingAs($normal)->get(route('data.activity-log'));
        $normalView->assertOk();
        $normalView->assertDontSee('99,999');
        $normalView->assertDontSee('Cost Breakdown');
    }

    public function test_module_filter_narrows_the_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ProjectionColumn::ensureSeededForMonth(now()->format('Y-m'));
        $column = ProjectionColumn::where('key', 'opening_shift')->firstOrFail();
        $this->actingAs($admin)->patchJson(
            route('data.projections.update-column', $column),
            ['net_income_target' => 55555]
        )->assertOk();

        CostBreakdownRole::ensureSeeded();
        $role = CostBreakdownRole::first();
        $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $role),
            ['base_salary' => 66666]
        )->assertOk();

        $response = $this->actingAs($admin)->get(route('data.activity-log', ['module' => 'projection']));
        $response->assertOk();
        $response->assertSee('55,555');
        $response->assertDontSee('66,666');
    }

    public function test_an_unknown_module_filter_is_ignored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('data.activity-log', ['module' => 'not_a_real_module']));

        $response->assertOk();
    }

    /** Date range filter (explicit request, 2026-10-12: "i want you to
     *  have date picker too like in the other page") — same shared
     *  DateRangeFilter every other Data Management report page already
     *  uses. An entry outside the selected range must not show, even
     *  though it would otherwise match every other filter. */
    public function test_the_date_range_filter_narrows_the_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // created_at isn't fillable (ActivityLog is append-only, UPDATED_AT
        // disabled) — create() would silently drop it and Eloquent auto-
        // stamps "now" instead, so it's forced in afterward via a plain
        // update() query (bypassing Eloquent's own timestamp management).
        $old = ActivityLog::create([
            'action' => 'projection.field_updated', 'description' => 'Updated something old (88,888)',
            'actor_name' => $admin->name,
        ]);
        ActivityLog::whereKey($old->id)->update(['created_at' => now()->subDays(10)]);

        ActivityLog::create([
            'action' => 'projection.field_updated', 'description' => 'Updated something recent (99,999)',
            'actor_name' => $admin->name,
        ]);

        // Default (no date_from/date_to on the URL) is "today only" —
        // same first-visit convention as every other report page.
        $defaultView = $this->actingAs($admin)->get(route('data.activity-log'));
        $defaultView->assertOk();
        $defaultView->assertSee('99,999');
        $defaultView->assertDontSee('88,888');

        // Explicit range covering the old entry too.
        $widerView = $this->actingAs($admin)->get(route('data.activity-log', [
            'date_from' => now()->subDays(15)->toDateString(), 'date_to' => now()->toDateString(),
        ]));
        $widerView->assertOk();
        $widerView->assertSee('99,999');
        $widerView->assertSee('88,888');
    }

    public function test_a_normal_user_cannot_filter_by_cost_breakdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $normal = User::factory()->create(['role' => 'normal']);
        CostBreakdownRole::ensureSeeded();
        $role = CostBreakdownRole::first();
        $this->actingAs($admin)->patchJson(
            route('data.cost-breakdown.update-role', $role),
            ['base_salary' => 77777]
        )->assertOk();

        // Even if a normal user crafts the URL directly, the controller's
        // own $visibleModules whitelist refuses to surface cost_breakdown
        // entries regardless of the requested module filter.
        $response = $this->actingAs($normal)->get(route('data.activity-log', ['module' => 'cost_breakdown']));
        $response->assertOk();
        $response->assertDontSee('77,777');
    }
}
