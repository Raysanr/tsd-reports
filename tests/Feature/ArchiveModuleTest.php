<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ArchiveDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** TSD Archiving System (2026-10-19) — pages, CRUD, uploads, access gate. */
class ArchiveModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_every_page_renders_for_an_admin_with_data(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);

        $root = Department::create(['name' => 'TSD Telesales', 'color' => 'sky']);
        $team = Department::create(['name' => 'Team A', 'color' => 'emerald', 'parent_id' => $root->id]);
        $head = Employee::create(['full_name' => 'Maria Santos', 'status' => 'active', 'department_id' => $root->id]);
        $root->update(['head_employee_id' => $head->id]);
        Employee::create(['full_name' => 'Pedro Reyes', 'status' => 'active', 'department_id' => $team->id, 'manager_id' => $head->id]);
        Employee::create(['full_name' => 'Ana Former', 'status' => 'resigned', 'left_at' => '2026-09-01']);
        ArchiveDocument::create(['title' => 'Handbook', 'category' => 'sop', 'path' => 'x/a.pdf', 'original_name' => 'a.pdf', 'size' => 2048, 'department_id' => $root->id, 'expires_at' => today()->addDays(5)]);

        foreach (['archive.overview', 'archive.org-chart', 'archive.departments', 'archive.people', 'archive.documents', 'archive.live-tsa', 'archive.records', 'archive.activity-log'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('archive.org-chart'))->assertSee('TSD Telesales')->assertSee('Team A')->assertSee('Maria Santos');
        $this->get(route('archive.documents', ['view' => 'list']))->assertOk()->assertSee('Handbook');
        $this->get(route('archive.people.show', $head))->assertOk()->assertSee('Pedro Reyes');
        $this->get(route('archive.records', ['tab' => 'people']))->assertSee('Ana Former');
        // resigned people are listed under "Former TSAs" below the current ones…
        $this->get(route('archive.people'))->assertSeeInOrder(['Pedro Reyes', 'Former TSAs', 'Ana Former']);
        // …but not on the Active tab
        $this->get(route('archive.people', ['status' => 'active']))->assertDontSee('Ana Former');
    }

    public function test_normal_user_cannot_open_the_module(): void
    {
        $this->actingAs(User::factory()->normal()->create());

        $this->get(route('archive.overview'))->assertForbidden();
        $this->post(route('archive.departments.store'), ['name' => 'X', 'color' => 'sky'])->assertForbidden();
    }

    public function test_department_cannot_be_moved_under_its_own_descendant(): void
    {
        $this->admin();
        $root = Department::create(['name' => 'Root', 'color' => 'sky']);
        $child = Department::create(['name' => 'Child', 'color' => 'sky', 'parent_id' => $root->id]);
        $grandchild = Department::create(['name' => 'Grandchild', 'color' => 'sky', 'parent_id' => $child->id]);

        $this->put(route('archive.departments.update', $root), ['name' => 'Root', 'color' => 'sky', 'parent_id' => $grandchild->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_department_create_archive_restore_is_logged(): void
    {
        $this->admin();
        $this->post(route('archive.departments.store'), ['name' => 'Logistics', 'code' => 'LOG', 'color' => 'amber'])->assertRedirect();
        $dept = Department::firstWhere('name', 'Logistics');

        $this->patch(route('archive.departments.archive', $dept));
        $this->assertNotNull($dept->fresh()->archived_at);
        $this->get(route('archive.records')); // consume the success flash
        $this->get(route('archive.org-chart'))->assertDontSee('Logistics');

        $this->patch(route('archive.departments.restore', $dept));
        $this->assertNull($dept->fresh()->archived_at);

        $this->assertSame(3, ActivityLog::where('action', 'like', 'archive.department.%')->count());
    }

    public function test_resigning_stamps_left_at_and_reinstate_clears_it(): void
    {
        $this->admin();
        $e = Employee::create(['full_name' => 'Juan', 'status' => 'active']);

        $this->put(route('archive.people.update', $e), ['full_name' => 'Juan', 'status' => 'resigned'])->assertSessionHasNoErrors();
        $this->assertSame(today()->toDateString(), $e->fresh()->left_at->toDateString());

        $this->patch(route('archive.people.reinstate', $e));
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNull($e->fresh()->left_at);
    }

    public function test_person_cannot_report_to_themselves(): void
    {
        $this->admin();
        $e = Employee::create(['full_name' => 'Juan', 'status' => 'active']);

        $this->put(route('archive.people.update', $e), ['full_name' => 'Juan', 'status' => 'active', 'manager_id' => $e->id])
            ->assertSessionHasErrors('manager_id');
    }

    public function test_deleting_a_department_head_clears_the_head_link(): void
    {
        $this->admin();
        $d = Department::create(['name' => 'Ops', 'color' => 'sky']);
        $e = Employee::create(['full_name' => 'Boss', 'status' => 'resigned', 'department_id' => $d->id]);
        $d->update(['head_employee_id' => $e->id]);

        $this->delete(route('archive.people.destroy', $e))->assertRedirect();
        $this->assertNull($d->fresh()->head_employee_id);
    }

    public function test_upload_download_trash_restore_and_force_delete(): void
    {
        $admin = $this->admin();
        Storage::fake(ArchiveDocument::DISK);

        $this->post(route('archive.documents.store'), [
            'files' => [UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('id.png')],
            'category' => 'contract',
            'tags' => 'hr, 2026 , hr',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, ArchiveDocument::count());
        $doc = ArchiveDocument::firstWhere('original_name', 'contract.pdf');
        $this->assertSame('contract', $doc->title);
        $this->assertSame('hr, 2026', $doc->tags);
        $this->assertSame($admin->id, $doc->uploaded_by);
        Storage::disk(ArchiveDocument::DISK)->assertExists($doc->path);

        $this->get(route('archive.documents.download', $doc))->assertOk()->assertDownload('contract.pdf');
        $this->get(route('archive.documents', ['q' => '2026']))->assertSee('contract');

        $this->delete(route('archive.documents.destroy', $doc));
        $this->assertSoftDeleted($doc);
        Storage::disk(ArchiveDocument::DISK)->assertExists($doc->path);

        $this->patch(route('archive.documents.restore', $doc->id));
        $this->assertNotSoftDeleted($doc);

        $doc->delete();
        $this->delete(route('archive.documents.force-destroy', $doc->id));
        $this->assertDatabaseMissing('archive_documents', ['id' => $doc->id]);
        Storage::disk(ArchiveDocument::DISK)->assertMissing($doc->path);
    }

    public function test_upload_rejects_disallowed_extensions(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);

        $this->post(route('archive.documents.store'), [
            'files' => [UploadedFile::fake()->create('evil.html', 1, 'text/html')],
            'category' => 'other',
        ])->assertSessionHasErrors('files.0');
        $this->assertSame(0, ArchiveDocument::count());
    }

    public function test_preview_only_serves_previewable_types_with_a_fixed_content_type(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);
        Storage::disk(ArchiveDocument::DISK)->put('a/x.png', 'not really html');
        Storage::disk(ArchiveDocument::DISK)->put('a/x.docx', 'doc');

        // Stored mime says text/html — preview must still send image/png.
        $png = ArchiveDocument::create(['title' => 'p', 'category' => 'other', 'path' => 'a/x.png', 'original_name' => 'x.png', 'mime_type' => 'text/html']);
        $docx = ArchiveDocument::create(['title' => 'd', 'category' => 'other', 'path' => 'a/x.docx', 'original_name' => 'x.docx']);

        $this->get(route('archive.documents.preview', $png))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('archive.documents.preview', $docx))->assertNotFound();
    }

    public function test_live_board_shows_active_tsas_with_todays_sales_and_hides_hidden_ones(): void
    {
        $this->admin();
        TsaShift::create(['tsa_key' => 'JOANA', 'display_name' => 'Joana', 'team' => 'SH Naturals', 'active' => true, 'status' => 'calling', 'status_changed_at' => now()->subMinutes(5)]);
        TsaShift::create(['tsa_key' => 'HIDDEN', 'display_name' => 'Hidden Person', 'team' => 'SH Naturals', 'active' => true, 'is_hidden' => true]);
        Order::factory()->create([
            'tsa_name' => 'JOANA', 'team' => 'SH Naturals', 'is_upsell' => true, 'amount' => 1500,
            'disposition' => 'Upsell w/ Confirmation', 'pancake_created_at' => now(), 'pancake_inserted_at' => now(),
        ]);

        $rows = \App\Support\LiveTsaBoard::rows();
        $this->assertNull($rows->firstWhere('name', 'Hidden Person'));
        $joana = $rows->firstWhere('name', 'Joana');
        $this->assertSame('working', $joana['group']);
        $this->assertSame(1, $joana['leads']);
        $this->assertSame('working', $rows->first()['group']); // working TSAs sort first

        $this->get(route('archive.live-tsa'))->assertOk()->assertSee('Joana')->assertDontSee('Hidden Person');
        $this->get(route('archive.live-tsa', ['partial' => 1]))->assertOk()->assertSee('data-live-board', false)->assertDontSee('<html', false);
    }

    public function test_connecting_teams_sets_main_parent_then_adds_extra_links_and_blocks_loops(): void
    {
        $this->admin();
        $ops = Department::create(['name' => 'Ops', 'color' => 'sky']);
        $qa = Department::create(['name' => 'QA', 'color' => 'sky']);
        $sales = Department::create(['name' => 'Sales', 'color' => 'sky']);
        $c = fn ($p, $ch) => $this->patchJson(route('archive.org-chart.connect'), ['type' => 'department', 'parent_id' => $p->id, 'child_id' => $ch->id]);

        $c($ops, $sales)->assertOk()->assertJson(['kind' => 'main']);
        $this->assertSame($ops->id, $sales->fresh()->parent_id);
        $c($qa, $sales)->assertOk()->assertJson(['kind' => 'extra']);
        $this->assertSame($ops->id, $sales->fresh()->parent_id); // main line untouched
        $c($qa, $sales)->assertOk()->assertJson(['kind' => 'none']);

        // QA under Sales would loop through the extra QA → Sales line
        $c($sales, $qa)->assertStatus(422);
        $this->patchJson(route('archive.org-chart.connect'), ['type' => 'department', 'parent_id' => $ops->id, 'child_id' => $ops->id])->assertStatus(422);

        $this->get(route('archive.org-chart'))->assertOk()->assertSee('"parent":"' . $qa->id . '"', false);

        // removing the main line promotes the extra one
        $this->patchJson(route('archive.org-chart.disconnect'), ['type' => 'department', 'parent_id' => $ops->id, 'child_id' => $sales->id])
            ->assertOk()->assertJson(['promoted' => $qa->id]);
        $this->assertSame($qa->id, $sales->fresh()->parent_id);
        $this->assertSame(0, \App\Models\OrgLink::count());

        $this->patchJson(route('archive.org-chart.disconnect'), ['type' => 'department', 'parent_id' => $qa->id, 'child_id' => $sales->id])->assertOk();
        $this->assertNull($sales->fresh()->parent_id);
    }

    public function test_people_can_report_to_several_managers_and_deleting_a_person_cleans_links(): void
    {
        $this->admin();
        $sup = Employee::create(['full_name' => 'Supervisor Sam', 'status' => 'active']);
        $qa = Employee::create(['full_name' => 'QA Quinn', 'status' => 'active']);
        $tsa = Employee::create(['full_name' => 'Agent Ana', 'status' => 'resigned']);
        $c = fn ($p, $ch) => $this->patchJson(route('archive.org-chart.connect'), ['type' => 'employee', 'parent_id' => $p->id, 'child_id' => $ch->id]);

        $c($sup, $tsa)->assertOk()->assertJson(['kind' => 'main']);
        $c($qa, $tsa)->assertOk()->assertJson(['kind' => 'extra'])->assertJsonFragment(['message' => 'Agent Ana also reports to QA Quinn']);
        $this->assertSame($sup->id, $tsa->fresh()->manager_id);
        $c($tsa, $sup)->assertStatus(422); // loop

        $this->delete(route('archive.people.destroy', $tsa));
        $this->assertSame(0, \App\Models\OrgLink::count());
    }

    public function test_normal_user_cannot_connect_org_chart_nodes(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $a = Department::create(['name' => 'A', 'color' => 'sky']);
        $b = Department::create(['name' => 'B', 'color' => 'sky']);
        $this->patchJson(route('archive.org-chart.connect'), ['type' => 'department', 'parent_id' => $a->id, 'child_id' => $b->id])->assertForbidden();
    }

    public function test_scoreboard_by_month_table_shows_sales_and_net_income_and_caches_months(): void
    {
        $this->admin();
        \App\Models\ScoreboardImport::query()->delete(); // the migration pre-loads the real Jan–Apr sheet data
        TsaShift::create(['tsa_key' => 'KATH', 'display_name' => 'Kath', 'team' => 'SH Naturals', 'active' => true]);
        $at = now()->startOfMonth()->addHours(10);
        Order::factory()->create(['tsa_name' => 'KATH', 'team' => 'SH Naturals', 'is_upsell' => true, 'amount' => 1000, 'status_code' => 1,
            'disposition' => 'Upsell w/ Confirmation', 'pancake_created_at' => $at, 'pancake_inserted_at' => $at]);

        $figures = \App\Support\LiveTsaBoard::yearFigures((int) now()->year);
        $kath = $figures['rows']->firstWhere('name', 'Kath');
        $month = $kath['months'][(int) now()->month];
        $this->assertGreaterThan(0, $month['sales']);
        $this->assertEqualsWithDelta($month['sales'], $kath['year']['sales'], 0.001);
        $this->assertSame((int) now()->month, $figures['bestMonth']['sales']);
        $this->assertArrayHasKey('net', $month);

        // month figures are cached (5 min for the current month)
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('scoreboard:figures:' . now()->format('Y-m')));

        $this->get(route('archive.live-tsa'))->assertOk()
            ->assertSee('Sales &amp; net income by month', false)->assertDontSee('Delivered');
        $this->get(route('archive.live-tsa', ['year' => 1999]))->assertOk(); // out-of-range year falls back
    }

    public function test_live_board_team_filter_limits_cards_and_is_remembered(): void
    {
        $this->admin();
        TsaShift::create(['tsa_key' => 'OPENER', 'display_name' => 'Opener Agent', 'team' => 'SH Naturals', 'active' => true]);
        TsaShift::create(['tsa_key' => 'CLOSER', 'display_name' => 'Closer Agent', 'team' => 'Eyecare Team', 'active' => true]);

        $this->get(route('archive.live-tsa', ['team' => 'sh-naturals']))->assertOk()->assertSee('Opener Agent')->assertDontSee('Closer Agent');
        // remembered for the next visit and for the 30s partial refresh
        $this->get(route('archive.live-tsa', ['partial' => 1]))->assertSee('Opener Agent')->assertDontSee('Closer Agent');
        $this->get(route('archive.live-tsa', ['team' => 'all']))->assertSee('Opener Agent')->assertSee('Closer Agent');
        $this->get(route('archive.live-tsa', ['team' => 'bogus']))->assertOk()->assertSee('Closer Agent');
    }

    public function test_roster_sync_creates_linked_people_in_their_team_and_marks_removed_tsas_resigned(): void
    {
        $this->admin();
        $shift = TsaShift::create(['tsa_key' => 'NEWBIE', 'display_name' => 'New Bie', 'team' => 'SH Naturals', 'active' => true]);
        $login = User::factory()->normal()->create(['tsa_id' => $shift->id]);

        $this->get(route('archive.people'))->assertOk()->assertSee('New Bie');
        $e = Employee::firstWhere('tsa_shift_id', $shift->id);
        $this->assertSame($login->id, $e->user_id);
        $this->assertSame('sh-naturals', $e->department->team_slug);
        $this->assertSame('active', $e->status);

        // hand edits survive a re-sync; running twice creates nothing new
        $e->update(['full_name' => 'Newbie Edited']);
        $this->assertSame(['created' => 0, 'updated' => 0], \App\Support\ArchiveRosterSync::run());
        $this->assertSame('Newbie Edited', $e->fresh()->full_name);

        $shift->delete();
        $this->post(route('archive.people.sync'))->assertRedirect();
        $this->assertSame('resigned', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->left_at);
    }

    public function test_coaching_records_crud_and_acknowledge(): void
    {
        $admin = $this->admin();
        $e = Employee::create(['full_name' => 'Coached Person', 'status' => 'active']);

        $this->post(route('archive.coaching.store'), [
            'employee_id' => $e->id, 'type' => 'qa', 'session_date' => today()->toDateString(), 'topic' => 'Opening spiel', 'score' => 87.5,
        ])->assertSessionHasNoErrors();
        $r = \App\Models\CoachingRecord::first();
        $this->assertSame($admin->id, $r->coach_id);
        $this->assertSame(87.5, $r->score);

        // coaching type drops the score; future dates rejected
        $this->put(route('archive.coaching.update', $r), ['employee_id' => $e->id, 'type' => 'coaching', 'session_date' => today()->toDateString(), 'topic' => 'Spiel', 'score' => 50])->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->score);
        $this->post(route('archive.coaching.store'), ['employee_id' => $e->id, 'type' => 'qa', 'session_date' => today()->addDay()->toDateString(), 'topic' => 'x'])->assertSessionHasErrors('session_date');

        $this->patch(route('archive.coaching.acknowledge', $r));
        $this->assertNotNull($r->fresh()->acknowledged_at);

        $this->get(route('archive.people.show', $e))->assertSee('Spiel');

        $this->delete(route('archive.coaching.destroy', $r));
        $this->assertSame(0, \App\Models\CoachingRecord::count());
    }

    public function test_performance_snapshot_freezes_a_month_and_regenerating_overwrites_it(): void
    {
        $this->admin();
        $shift = TsaShift::create(['tsa_key' => 'SNAP', 'display_name' => 'Snap Agent', 'team' => 'SH Naturals', 'active' => true]);
        $shift->forceFill(['created_at' => now()->subMonths(3)])->save();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        $mk = fn (array $o) => Order::factory()->create(array_merge(['tsa_name' => 'SNAP', 'team' => 'SH Naturals', 'pancake_created_at' => $lastMonth->copy()->addDays(3), 'pancake_inserted_at' => $lastMonth->copy()->addDays(3)], $o));
        $mk(['is_upsell' => true, 'status_code' => 3, 'amount' => 800]);
        $mk(['is_returned_upsell' => true, 'status_code' => 5, 'returned_upsell_amount' => 300, 'amount' => 900]);

        $this->artisan('archive:snapshot-performance')->assertSuccessful();
        $snap = \App\Models\PerformanceSnapshot::where('tsa_shift_id', $shift->id)->first();
        $this->assertSame($lastMonth->toDateString(), $snap->month->toDateString());
        $this->assertSame(800.0, $snap->delivered);
        $this->assertSame(300.0, $snap->rts);
        $this->assertSame(2, $snap->leads);

        $mk(['is_upsell' => true, 'status_code' => 3, 'amount' => 200]);
        $this->post(route('archive.performance.generate'), ['month' => $lastMonth->format('Y-m')])->assertRedirect();
        $this->assertSame(1, \App\Models\PerformanceSnapshot::where('tsa_shift_id', $shift->id)->count());
        $this->assertSame(1000.0, $snap->fresh()->delivered);

        $this->get(route('archive.performance', ['month' => $lastMonth->format('Y-m')]))->assertOk()->assertSee('Snap Agent');
        $this->post(route('archive.performance.generate'), ['month' => now()->addMonths(2)->format('Y-m')])->assertStatus(422);
        $this->artisan('archive:snapshot-performance', ['--month' => 'bad'])->assertFailed();
    }

    public function test_lifecycle_timeline_records_changes_automatically_and_manual_milestones(): void
    {
        $this->admin();
        $a = Department::create(['name' => 'Team Opening', 'color' => 'sky']);
        $b = Department::create(['name' => 'Team Closing', 'color' => 'sky']);
        $e = Employee::create(['full_name' => 'Life Cycle', 'status' => 'active', 'department_id' => $a->id, 'position' => 'Trainee']);

        $this->put(route('archive.people.update', $e), ['full_name' => 'Life Cycle', 'status' => 'active', 'department_id' => $b->id, 'position' => 'Telesales Agent'])->assertSessionHasNoErrors();
        $this->put(route('archive.people.update', $e), ['full_name' => 'Life Cycle', 'status' => 'resigned', 'department_id' => $b->id, 'position' => 'Telesales Agent']);

        $types = $e->events()->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['hired', 'team', 'position', 'resigned'], $types);
        $this->assertStringContainsString('Moved to Team Closing', $e->events()->where('type', 'team')->value('title'));

        $this->post(route('archive.people.events.store', $e), ['title' => 'Top seller of September', 'occurred_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->get(route('archive.people.show', $e))->assertOk()->assertSee('Lifecycle timeline')->assertSee('Top seller of September')->assertSee('Moved to Team Closing');

        $milestone = $e->events()->where('type', 'milestone')->first();
        $auto = $e->events()->where('type', 'hired')->first();
        $this->delete(route('archive.people.events.destroy', [$e, $auto]))->assertNotFound(); // automatic events can't be deleted
        $this->delete(route('archive.people.events.destroy', [$e, $milestone]))->assertRedirect();
        $this->assertNull($milestone->fresh());
    }

    /** Incidents keep files and an outcome; no reply workflow (2026-10-22). */
    public function test_incidents_record_files_without_a_reply_workflow(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);
        $e = Employee::create(['full_name' => 'Late Larry', 'status' => 'active']);
        $base = ['employee_id' => $e->id, 'type' => 'nte', 'incident_date' => today()->subDays(7)->toDateString(), 'title' => 'Late three times'];

        $this->post(route('archive.incidents.store'), $base + [
            'files' => [UploadedFile::fake()->create('nte-larry.pdf', 40, 'application/pdf'), UploadedFile::fake()->image('cctv.png')],
        ])->assertSessionHasNoErrors();
        $i = \App\Models\Incident::first();
        $this->assertSame(2, $i->attachments()->count());
        $this->assertSame($e->id, $i->attachments()->first()->employee_id);

        $this->get(route('archive.incidents'))->assertOk()
            ->assertSee('Late Larry')->assertSee('nte-larry.pdf')->assertSee('2 files')
            ->assertDontSee('Awaiting reply')->assertDontSee('Replied')->assertDontSee('Reply due');
        // incident files stay out of the general Documents list
        $this->get(route('archive.documents'))->assertDontSee('nte-larry.pdf');

        // edit: outcome + one more file
        $this->put(route('archive.incidents.update', $i), $base + ['decision' => 'Verbal reminder', 'files' => [UploadedFile::fake()->create('reply.docx', 10)]])->assertSessionHasNoErrors();
        $this->assertSame(3, $i->attachments()->count());
        $this->get(route('archive.people.show', $e))->assertSee('Verbal reminder')->assertSee('reply.docx');

        // bad file types are refused
        $this->put(route('archive.incidents.update', $i), $base + ['files' => [UploadedFile::fake()->create('x.exe', 5)]])->assertSessionHasErrors('files.0');

        // deleting the incident sends its files to the trash, not oblivion
        $this->delete(route('archive.incidents.destroy', $i));
        $this->assertSame(0, \App\Models\Incident::count());
        $this->assertSame(3, ArchiveDocument::onlyTrashed()->count());
    }

    public function test_library_keeps_versions_and_can_restore(): void
    {
        $this->admin();
        $this->post(route('archive.library.store'), ['kind' => 'script', 'folder' => 'tsd-call-flow', 'title' => 'Opening spiel', 'body' => 'Hello v1'])->assertRedirect();
        $item = \App\Models\LibraryItem::first();

        // details-only change: no new version
        $this->put(route('archive.library.update', $item), ['kind' => 'rebuttal', 'folder' => 'tsd-call-flow', 'title' => 'Opening spiel', 'body' => 'Hello v1']);
        $this->assertSame(1, $item->fresh()->version);

        $this->put(route('archive.library.update', $item), ['kind' => 'script', 'folder' => 'tsd-call-flow', 'title' => 'Opening spiel', 'body' => 'Hello v2', 'change_note' => 'warmer'])->assertSessionHasNoErrors();
        $this->assertSame(2, $item->fresh()->version);

        $this->patch(route('archive.library.restore', [$item, 1]));
        $item->refresh();
        $this->assertSame(3, $item->version);
        $this->assertSame('Hello v1', $item->body);
        $this->assertSame(3, $item->versions()->count());

        $this->get(route('archive.library', ['q' => 'Hello']))->assertOk()->assertSee('Opening spiel');
        $this->get(route('archive.library.show', $item))->assertOk()->assertSee('Restored from v1');

        $this->patch(route('archive.library.archive', $item));
        $this->get(route('archive.library')); // consume the success flash
        $this->get(route('archive.library'))->assertDontSee('Opening spiel');
        $this->get(route('archive.records', ['tab' => 'library']))->assertOk()->assertSee('Opening spiel');
        $this->delete(route('archive.library.destroy', $item->fresh()))->assertRedirect(); // allowed once archived
        $this->assertSame(0, \App\Models\LibraryVersion::count());

        $this->post(route('archive.library.store'), ['kind' => 'faq', 'folder' => 'training-process', 'title' => 'Shipping FAQ', 'body' => 'x']);
        $faq = \App\Models\LibraryItem::firstWhere('title', 'Shipping FAQ');
        $this->delete(route('archive.library.destroy', $faq))->assertNotFound(); // not archived yet
        $this->patch(route('archive.library.archive', $faq));
        $this->patch(route('archive.library.unarchive', $faq));
        $this->assertNull($faq->fresh()->archived_at);
    }

    public function test_org_chart_canvas_saves_card_positions_and_auto_arrange_clears_them(): void
    {
        $this->admin();
        $d = Department::create(['name' => 'Canvas Team', 'color' => 'sky']);
        $e = Employee::create(['full_name' => 'Canvas Person', 'status' => 'active']);

        $this->patchJson(route('archive.org-chart.position'), ['type' => 'department', 'id' => $d->id, 'x' => 420, 'y' => 180])->assertOk();
        $this->patchJson(route('archive.org-chart.position'), ['type' => 'employee', 'id' => $e->id, 'x' => 50, 'y' => 60])->assertOk();
        $this->patchJson(route('archive.org-chart.position'), ['type' => 'employee', 'id' => $e->id, 'x' => -5, 'y' => 60])->assertStatus(422);
        $this->assertSame([420, 180], [$d->fresh()->chart_x, $d->fresh()->chart_y]);
        $this->assertSame(0, $e->events()->where('type', '!=', 'hired')->count()); // moving a card isn't a lifecycle event

        $this->get(route('archive.org-chart'))->assertOk()->assertSee('data-x="420" data-y="180"', false);

        $this->post(route('archive.org-chart.reset'), ['view' => 'departments'])->assertRedirect();
        $this->assertNull($d->fresh()->chart_x);
        $this->assertSame(50, $e->fresh()->chart_x); // only the chosen view is reset
    }

    public function test_profile_photo_upload_serve_replace_and_remove(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);
        $e = Employee::create(['full_name' => 'Photo Person', 'status' => 'active']);

        // from the profile page
        $this->post(route('archive.people.photo.update', $e), ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertSessionHasNoErrors();
        $first = $e->fresh()->photo_path;
        Storage::disk(ArchiveDocument::DISK)->assertExists($first);
        $this->get($e->fresh()->photoUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('archive.people'))->assertSee($e->fresh()->photoUrl(), false);

        // replacing through the edit form deletes the old file
        $this->put(route('archive.people.update', $e), ['full_name' => 'Photo Person', 'status' => 'active', 'photo' => UploadedFile::fake()->image('new.png', 200, 200)])->assertSessionHasNoErrors();
        Storage::disk(ArchiveDocument::DISK)->assertMissing($first);
        $this->assertStringEndsWith('.png', $e->fresh()->photo_path);

        // non-images and oversized files are rejected
        $this->post(route('archive.people.photo.update', $e), ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->post(route('archive.people.photo.update', $e), ['photo' => UploadedFile::fake()->image('big.jpg')->size(6000)])->assertSessionHasErrors('photo');

        // "remove photo" tick in the form
        $second = $e->fresh()->photo_path;
        $this->put(route('archive.people.update', $e), ['full_name' => 'Photo Person', 'status' => 'active', 'remove_photo' => 1])->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->photo_path);
        Storage::disk(ArchiveDocument::DISK)->assertMissing($second);
        $this->get(route('archive.people.photo', $e))->assertNotFound();

        // normal users can't fetch photos
        $this->post(route('archive.people.photo.update', $e), ['photo' => UploadedFile::fake()->image('again.jpg')]);
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('archive.people.photo', $e))->assertForbidden();
    }

    public function test_side_links_join_any_two_cards_even_a_card_and_its_parent(): void
    {
        $this->admin();
        $top = Department::create(['name' => 'Top', 'color' => 'sky']);
        $tiktok = Department::create(['name' => 'TikTok', 'color' => 'sky', 'parent_id' => $top->id]);
        $closing = Department::create(['name' => 'Closing', 'color' => 'sky']);
        $side = fn ($a, $b) => $this->patchJson(route('archive.org-chart.connect'), ['mode' => 'side', 'type' => 'department', 'a' => $a->id, 'b' => $b->id]);

        // a "put under" line from TikTok to its own parent is a loop...
        $this->patchJson(route('archive.org-chart.connect'), ['type' => 'department', 'parent_id' => $tiktok->id, 'child_id' => $top->id])->assertStatus(422);
        // ...but a side link between them is fine, in either direction, once
        $side($tiktok, $top)->assertOk()->assertJson(['kind' => 'side']);
        $side($top, $tiktok)->assertOk()->assertJson(['kind' => 'none']);
        $side($tiktok, $closing)->assertOk()->assertJson(['kind' => 'side']);
        $side($top, $top)->assertStatus(422);

        // side links never change the tree or count as "also under"
        $this->assertSame($top->id, $tiktok->fresh()->parent_id);
        $this->assertNull($closing->fresh()->parent_id);
        $this->get(route('archive.org-chart'))->assertOk()
            ->assertSee('"a":"' . min($top->id, $tiktok->id) . '"', false)
            ->assertSee('data-side="left"', false);

        $this->patchJson(route('archive.org-chart.disconnect'), ['mode' => 'side', 'type' => 'department', 'a' => $top->id, 'b' => $tiktok->id])->assertOk();
        $this->assertSame(1, \App\Models\OrgLink::where('kind', 'side')->count());
    }

    public function test_scoreboard_cards_show_total_sales_and_net_income_from_the_summary_sales_report_sources(): void
    {
        $this->admin();
        $shift = TsaShift::create(['tsa_key' => 'SCORE', 'display_name' => 'Score Agent', 'team' => 'SH Naturals', 'active' => true]);
        Order::factory()->create([
            'tsa_name' => 'SCORE', 'team' => 'SH Naturals', 'is_upsell' => true, 'amount' => 1200, 'status_code' => 1,
            'disposition' => 'Upsell w/ Confirmation', 'pancake_created_at' => now()->startOfMonth(), 'pancake_inserted_at' => now()->startOfMonth(),
        ]);

        $from = now()->startOfMonth();
        $totals = \App\Support\LiveTsaBoard::salesAndNetIncome(collect([$shift]), $from, now());

        // Total Sales = the same tally upsell_sales the Summary Sales Report shows as Gross Sales
        $expectedSales = \App\Support\ProductPerformance::tally(Order::where('tsa_name', 'SCORE')->get())['upsell_sales'];
        $this->assertEqualsWithDelta($expectedSales, $totals[$shift->id]['sales'], 0.001);
        $this->assertGreaterThan(0, $totals[$shift->id]['sales']);

        // Total Net Income = Expected Income's per-TSA Net Income, summed over the month
        $expectedNet = \App\Http\Controllers\DataManagement\ExpectedIncomeController::netIncomeByTsaAndDate($from->toDateString(), now()->toDateString())
            ->filter(fn ($v, $k) => str_starts_with((string) $k, $shift->id . ':'))->sum();
        $this->assertEqualsWithDelta(round($expectedNet, 2), $totals[$shift->id]['net'], 0.01);

        $this->get(route('archive.live-tsa'))->assertOk()->assertSee('Score Agent')
            ->assertSee('Total sales · ' . now()->format('M'))->assertSee('Total net income · ' . now()->format('M'));
    }

    public function test_sheet_months_replace_system_figures_and_list_former_tsas(): void
    {
        $this->admin();
        \App\Models\ScoreboardImport::query()->delete(); // the migration pre-loads the real Jan–Apr sheet data
        Employee::where('notes', 'like', 'Imported from the IS Report%')->delete();
        \App\Support\ArchiveRosterSync::ensureTeams(); // Team Opening / Team Closing
        $joana = TsaShift::firstWhere('tsa_key', 'Joana') ?? TsaShift::create(['tsa_key' => 'Joana', 'display_name' => 'Joana', 'team' => 'Eyecare Team', 'active' => true]);
        $joana->update(['active' => true, 'is_hidden' => false, 'team' => 'Eyecare Team']);
        $file = tempnam(sys_get_temp_dir(), 'sheet');
        file_put_contents($file, json_encode(['months' => [
            '2026-01' => ['sheet' => 'JAN', 'net_row' => 'x', 'people' => [
                ['name' => 'JOANNA', 'team' => 'eyecare', 'sales' => 1000, 'net' => 300],      // alias → Joana
                ['name' => 'LOVELY', 'team' => 'sh-naturals', 'sales' => 2000, 'net' => -50], // not on roster
            ]],
            '2026-02' => ['sheet' => 'FEB', 'net_row' => 'x', 'people' => [
                ['name' => 'LOVELY', 'team' => 'sh-naturals', 'sales' => 500, 'net' => 100],
            ]],
        ]]));

        $r = \App\Support\ScoreboardSheetImport::run($file);
        $this->assertSame(['rows' => 3, 'matched' => 1, 'former' => ['LOVELY']], $r);
        \App\Support\ScoreboardSheetImport::run($file); // idempotent
        $this->assertSame(3, \App\Models\ScoreboardImport::count());

        $f = \App\Support\LiveTsaBoard::yearFigures(2026);
        $joanaRow = $f['rows']->firstWhere('id', $joana->id);
        $this->assertSame(['sales' => 1000.0, 'net' => 300.0], $joanaRow['months'][1]);
        $lovely = $f['rows']->firstWhere('name', 'Lovely');
        $this->assertTrue($lovely['former']);
        $this->assertSame(['sales' => 2500.0, 'net' => 50.0], $lovely['year']);
        $this->assertSame([1, 2], $f['sheetMonths']);
        $this->assertSame(3000.0, $f['monthTotals'][1]['sales']);

        // team filter applies to former rows by their sheet team
        $closing = \App\Support\LiveTsaBoard::yearFigures(2026, 'Eyecare Team');
        $this->assertNull($closing['rows']->firstWhere('name', 'Lovely'));

        $this->get(route('archive.live-tsa', ['year' => 2026, 'team' => 'all']))->assertOk()
            ->assertSee('Lovely')->assertSee('Former')->assertSee('from the Google Sheet', false);

        // former TSAs also land in People as Resigned, once, in their sheet team
        $person = Employee::firstWhere('full_name', 'Lovely');
        $this->assertSame('resigned', $person->status);
        $this->assertSame('sh-naturals', $person->department?->team_slug);
        $this->assertSame(0, \App\Support\ScoreboardSheetImport::syncFormerPeople());
        $this->assertSame(0, $person->events()->count()); // no misleading "Joined today" entry
        $this->assertSame('MJ Caliwan', \App\Support\ScoreboardSheetImport::displayName('MJ CALIWAN'));
        $this->get(route('archive.people', ['status' => 'resigned']))->assertSee('Lovely');
    }

    public function test_bundled_jan_apr_sheet_data_matches_the_sheets_own_monthly_totals(): void
    {
        $data = json_decode(file_get_contents(database_path('data/scoreboard-sheet-2026-jan-apr.json')), true);
        $expected = ['2026-01' => 4142288, '2026-02' => 2948874, '2026-03' => 2146752, '2026-04' => 2052166]; // sheet TELESALES Gross Sales
        foreach ($expected as $ym => $total) {
            $this->assertEqualsWithDelta($total, array_sum(array_column($data['months'][$ym]['people'], 'sales')), 0.5, $ym);
        }
    }

    public function test_documented_process_folders_hold_uploaded_files_and_pages(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);

        $this->get(route('archive.library'))->assertOk()
            ->assertSeeInOrder(['2026 Report Template', 'Product Overview', 'Product Spiel', 'Training Process', 'TSD - Workflow System', 'TSD Avatar', 'TSD Call Flow', 'TSD Compensation']);

        $this->from(route('archive.library.folder', 'product-spiel'))
            ->post(route('archive.documents.store'), [
                'files' => [UploadedFile::fake()->create('pterylief-spiel.pdf', 50, 'application/pdf')],
                'process_folder' => 'product-spiel', 'tags' => 'pterylief, opening', // no category — the folder is the category
            ])->assertRedirect(route('archive.library.folder', 'product-spiel'));
        $doc = ArchiveDocument::firstWhere('original_name', 'pterylief-spiel.pdf');
        $this->assertSame('product-spiel', $doc->process_folder);
        $this->assertSame('sop', $doc->category);
        $this->get(route('archive.library.folder', 'product-spiel'))->assertDontSee('u_cat', false); // simple form there
        // the general Documents upload still requires a category
        $this->post(route('archive.documents.store'), ['files' => [UploadedFile::fake()->create('y.pdf', 5, 'application/pdf')]])->assertSessionHasErrors('category');

        $this->post(route('archive.library.store'), ['kind' => 'script', 'folder' => 'product-spiel', 'title' => 'PteryLief opening', 'body' => 'Hi po!']);

        $this->get(route('archive.library.folder', 'product-spiel'))->assertOk()->assertSee('pterylief-spiel')->assertSee('PteryLief opening');
        $this->get(route('archive.library.folder', 'tsd-avatar'))->assertOk()->assertDontSee('pterylief-spiel');
        $this->get(route('archive.library.folder', 'nope'))->assertNotFound();
        $this->get(route('archive.library', ['q' => 'pterylief']))->assertOk()->assertSee('pterylief-spiel')->assertSee('PteryLief opening');
        // Documented Process files are kept out of the general Documents list
        $this->get(route('archive.documents'))->assertDontSee('pterylief-spiel');
        // a bogus folder is rejected
        $this->post(route('archive.documents.store'), ['files' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], 'category' => 'sop', 'process_folder' => 'hacked'])
            ->assertSessionHasErrors('process_folder');
    }

    public function test_documented_process_folders_can_have_subfolders(): void
    {
        $this->admin();
        Storage::fake(ArchiveDocument::DISK);
        $root = route('archive.library.folder', 'product-spiel');

        // create a subfolder, and one inside it
        $this->from($root)->post(route('archive.library.subfolders.store', 'product-spiel'), ['name' => 'Pterylief'])->assertRedirect($root);
        $ptery = \App\Models\ProcessSubfolder::firstWhere('name', 'Pterylief');
        $this->assertSame('product-spiel', $ptery->folder);
        $this->assertNull($ptery->parent_id);
        $this->post(route('archive.library.subfolders.store', 'product-spiel'), ['name' => 'Opening', 'parent_id' => $ptery->id]);
        $opening = \App\Models\ProcessSubfolder::firstWhere('name', 'Opening');
        $this->assertSame($ptery->id, $opening->parent_id);
        // same name twice in one place is refused
        $this->post(route('archive.library.subfolders.store', 'product-spiel'), ['name' => 'Pterylief'])->assertSessionHas('error');
        $this->assertSame(1, \App\Models\ProcessSubfolder::where('name', 'Pterylief')->count());
        // a parent from another folder is rejected
        $this->post(route('archive.library.subfolders.store', 'tsd-avatar'), ['name' => 'X', 'parent_id' => $ptery->id])->assertSessionHasErrors('parent_id');

        // upload into the nested subfolder
        $this->post(route('archive.documents.store'), [
            'files' => [UploadedFile::fake()->create('ptery-opening.pdf', 20, 'application/pdf')],
            'process_folder' => 'product-spiel', 'process_subfolder_id' => $opening->id,
        ])->assertSessionHasNoErrors();
        $doc = ArchiveDocument::firstWhere('original_name', 'ptery-opening.pdf');
        $this->assertSame($opening->id, $doc->process_subfolder_id);

        // the file shows only in its own subfolder; folders show as cards with a breadcrumb
        $this->get($root)->assertOk()->assertSee('Pterylief')->assertDontSee('ptery-opening');
        $this->get(route('archive.library.subfolder', ['product-spiel', $ptery]))->assertOk()->assertSee('Opening')->assertDontSee('ptery-opening');
        $this->get(route('archive.library.subfolder', ['product-spiel', $opening]))->assertOk()
            ->assertSeeInOrder(['Product Spiel', 'Pterylief', 'Opening'])->assertSee('ptery-opening');
        $this->get(route('archive.library.subfolder', ['tsd-avatar', $opening]))->assertNotFound();
        $this->get(route('archive.library', ['q' => 'ptery-opening']))->assertSee('Pterylief / Opening');
        // a subfolder id from another folder is rejected on upload
        $this->post(route('archive.documents.store'), ['files' => [UploadedFile::fake()->create('z.pdf', 5, 'application/pdf')], 'process_folder' => 'tsd-avatar', 'process_subfolder_id' => $opening->id])
            ->assertSessionHasErrors('process_subfolder_id');

        // rename
        $this->patch(route('archive.library.subfolders.rename', $opening), ['name' => 'Opening Spiel'])->assertSessionHas('success');
        $this->assertSame('Opening Spiel', $opening->fresh()->name);

        // a folder with something inside can't be deleted; once empty it can
        $this->delete(route('archive.library.subfolders.destroy', $ptery))->assertSessionHas('error');
        $this->delete(route('archive.library.subfolders.destroy', $opening))->assertSessionHas('error');
        $doc->forceDelete();
        $this->delete(route('archive.library.subfolders.destroy', $opening))->assertRedirect(route('archive.library.subfolder', ['product-spiel', $ptery]));
        $this->delete(route('archive.library.subfolders.destroy', $ptery))->assertRedirect($root);
        $this->assertSame(0, \App\Models\ProcessSubfolder::count());
    }

    public function test_people_page_is_the_manpower_sheet_and_saves_cells_inline(): void
    {
        $this->admin();
        $e = Employee::create(['full_name' => 'Joanna Marie Caluag', 'status' => 'active', 'hired_at' => '2026-04-15']);
        $url = route('archive.people.manpower', $e);

        $this->get(route('archive.people'))->assertOk()
            ->assertSeeInOrder(['Name', 'Birthday', 'Hiring date', 'Status', 'Shift schedule', 'Status (manual)', 'Date of last day'])
            ->assertSee('Joanna Marie Caluag')->assertSee('Probi')->assertSee('Midshift');

        $this->patchJson($url, ['field' => 'birthday', 'value' => '2002-08-10'])->assertOk();
        // readable dates, and a birthday this month is highlighted
        $this->patchJson($url, ['field' => 'hired_at', 'value' => '2023-10-10'])->assertOk();
        $this->get(route('archive.people'))->assertSee('Oct. 10, 2023')->assertSee('Aug. 10, 2002');
        $e->update(['birthday' => now()->startOfMonth()->subYears(30)]);
        $this->get(route('archive.people'))->assertSee('Birthday this month')->assertSee('1 birthday in ' . now()->format('F'));
        $e->update(['birthday' => '2002-08-10']);
        $this->patchJson($url, ['field' => 'employment_status', 'value' => 'probi'])->assertOk()->assertJson(['moved' => false]);
        $this->patchJson($url, ['field' => 'shift_schedule', 'value' => 'opening'])->assertOk();
        $this->patchJson($url, ['field' => 'manual_status', 'value' => 'For regularization'])->assertOk();
        $e->refresh();
        $this->assertSame('2002-08-10', $e->birthday->toDateString());
        $this->assertSame('probi', $e->employment_status);
        $this->assertSame('opening', $e->shift_schedule);
        $this->assertSame('For regularization', $e->manual_status);

        // bad values are refused; unknown fields too
        $this->patchJson($url, ['field' => 'employment_status', 'value' => 'boss'])->assertStatus(422);
        $this->patchJson($url, ['field' => 'full_name', 'value' => 'X'])->assertStatus(422);

        // Resigned moves them to Former TSAs; Regular brings them back
        $this->patchJson($url, ['field' => 'employment_status', 'value' => 'resigned'])->assertJson(['moved' => true]);
        $this->patchJson($url, ['field' => 'left_at', 'value' => '2026-10-20'])->assertOk();
        $this->assertSame('resigned', $e->fresh()->status);
        $this->assertSame('2026-10-20', $e->fresh()->left_at->toDateString());
        $this->patchJson($url, ['field' => 'employment_status', 'value' => 'regular'])->assertJson(['moved' => true]);
        $this->assertSame('active', $e->fresh()->status);

        // clearing a cell stores nothing
        $this->patchJson($url, ['field' => 'manual_status', 'value' => '  '])->assertOk();
        $this->assertNull($e->fresh()->manual_status);
    }

    /** The Edit person modal no longer has TSA roster / App login (2026-10-22);
     *  saving it must keep the links a person already has. */
    public function test_editing_a_person_keeps_existing_roster_and_login_links(): void
    {
        $admin = $this->admin();
        $tsa = TsaShift::create(['tsa_key' => 'KEEPME', 'display_name' => 'Keep Me', 'team' => 'SH Naturals', 'active' => true]);
        $e = Employee::create(['full_name' => 'Keep Me', 'status' => 'active', 'tsa_shift_id' => $tsa->id, 'user_id' => $admin->id]);

        $this->put(route('archive.people.update', $e), ['full_name' => 'Keep Me Renamed', 'status' => 'active'])->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('Keep Me Renamed', $e->full_name);
        $this->assertSame($tsa->id, $e->tsa_shift_id);
        $this->assertSame($admin->id, $e->user_id);
        $this->get(route('archive.people'))->assertDontSee('e_tsa', false)->assertDontSee('App login');
    }
}
