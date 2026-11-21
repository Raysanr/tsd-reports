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

        $this->get(route('archive.org-chart', ['mode' => 'arrange']))->assertOk()->assertSee('"parent":"' . $qa->id . '"', false);

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
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('scoreboard:figures:v5:' . now()->format('Y-m')));

        $this->get(route('archive.live-tsa'))->assertOk()
            ->assertSee('Sales &amp; net income by month', false)->assertDontSee('Delivered');
        $this->get(route('archive.live-tsa', ['year' => 1999]))->assertOk(); // out-of-range year falls back
    }

    /** Partner systems are on the Scoreboard too (2026-11-19). A TSA who
     *  moved from TSD to a partner is one row in the by-month table. */
    public function test_scoreboard_includes_partner_tsas_and_merges_moved_ones(): void
    {
        $this->admin();
        $this->artisan('migrate', ['--database' => \App\Support\DataSet::PARTNERS]);
        \App\Models\ScoreboardImport::query()->delete();
        TsaShift::create(['tsa_key' => 'MOVER', 'display_name' => 'Mover Person', 'team' => 'SH Naturals', 'active' => true]);
        TsaShift::create(['tsa_key' => 'STAYER', 'display_name' => 'Stayer Person', 'team' => 'SH Naturals', 'active' => true]);
        \App\Support\DataSet::onPartners(function () {
            TsaShift::create(['tsa_key' => 'MOVER', 'display_name' => 'PERSON mover', 'team' => 'M&J', 'active' => true]);
            TsaShift::create(['tsa_key' => 'PARTNERONLY', 'display_name' => 'Partner Only', 'team' => 'M&J', 'active' => true]);
        });

        $this->get(route('archive.live-tsa', ['team' => 'all']))->assertOk()
            ->assertSee('Partner Only')->assertSee('Stayer Person')->assertSee('M&amp;J', false);

        $figures = \App\Support\LiveTsaBoard::mergeYearFigures(
            \App\Support\LiveTsaBoard::yearFigures((int) now()->year),
            \App\Support\LiveTsaBoard::partnerYearFigures((int) now()->year)
        );
        $movers = $figures['rows']->filter(fn ($r) => \App\Support\LiveTsaBoard::sameName($r['name'], 'Mover Person'));
        $this->assertCount(1, $movers);
        $this->assertStringStartsWith('partner:', $movers->first()['id']);

        // A partner tab shows only that partner.
        $partnerTab = 'p:' . collect(\App\Support\DataSet::partnerTeams())->search(fn ($p) => $p['name'] === 'M&J');
        $this->get(route('archive.live-tsa', ['team' => $partnerTab]))->assertOk()
            ->assertSee('Partner Only')->assertDontSee('Stayer Person');
    }

    /** A TSA removed from TSD (moved to a partner) keeps her TSD months up
     *  to her last day, joined with her partner row (2026-11-19, Julie). */
    public function test_scoreboard_keeps_a_moved_tsas_tsd_months(): void
    {
        $this->admin();
        $this->artisan('migrate', ['--database' => \App\Support\DataSet::PARTNERS]);
        \App\Models\ScoreboardImport::query()->delete();
        $gone = TsaShift::create(['tsa_key' => 'GONE', 'display_name' => 'Gone Person', 'team' => 'SH Naturals', 'active' => true]);
        $at = now()->startOfMonth()->addHours(10);
        Order::factory()->create(['tsa_name' => 'GONE', 'team' => 'SH Naturals', 'is_upsell' => true, 'amount' => 1000, 'status_code' => 1,
            'disposition' => 'Upsell w/ Confirmation', 'pancake_created_at' => $at, 'pancake_inserted_at' => $at]);
        $gone->forceFill(['last_day' => now()->toDateString(), 'is_hidden' => true])->save(); // hidden after leaving
        $gone->delete();

        $tsd = \App\Support\LiveTsaBoard::yearFigures((int) now()->year);
        $row = $tsd['rows']->firstWhere('name', 'Gone Person');
        $this->assertNotNull($row);
        $this->assertTrue($row['former']);
        $this->assertGreaterThan(0, $row['months'][(int) now()->month]['sales']);

        \App\Support\DataSet::onPartners(fn () => TsaShift::create(['tsa_key' => 'MLC:Gone', 'display_name' => 'Gone', 'team' => 'MLC', 'active' => true]));
        $merged = \App\Support\LiveTsaBoard::mergeYearFigures($tsd, \App\Support\LiveTsaBoard::partnerYearFigures((int) now()->year));
        $rows = $merged['rows']->filter(fn ($r) => \App\Support\LiveTsaBoard::sameName($r['name'], 'Gone'));
        $this->assertCount(1, $rows);
        $this->assertSame('Gone Person', $rows->first()['name']);
        $this->assertFalse($rows->first()['former']);
        $this->assertGreaterThan(0, $rows->first()['months'][(int) now()->month]['sales']);
    }

    /** One card per person (2026-11-20): Jhun's TSD and M&J cards combine,
     *  and SH Mari (M&J + PEAK.CO) joins Marisol through NAME_ALIASES. */
    public function test_scoreboard_combines_cards_and_rows_for_the_same_person(): void
    {
        $row = fn ($id, $name, $team, $partner = false, $former = false) => ['id' => $id, 'name' => $name, 'team' => $team, 'partner' => $partner, 'former' => $former, 'photo' => null];
        $rows = collect([
            $row(1, 'Jhun Ematong', 'CLOSING'),
            $row('partner:7', 'Jhun', 'M&J', true),
            $row('partner:8', 'SH Mari', 'M&J', true),
            $row('partner:9', 'SH Mari', 'PEAK.CO', true),
            $row(2, 'Gemma De Guzman', 'OPENING'),
        ]);
        $totals = [1 => ['sales' => 8700, 'net' => 4993.84], 'partner:7' => ['sales' => 137266, 'net' => -1616.67],
            'partner:8' => ['sales' => 3302, 'net' => -650.06], 'partner:9' => ['sales' => 125375, 'net' => -12126.91], 2 => ['sales' => 1, 'net' => 1]];

        [$cards, $totals] = \App\Support\LiveTsaBoard::mergeCards($rows, $totals);
        $this->assertCount(3, $cards);
        $jhun = $cards->firstWhere('name', 'Jhun Ematong');
        $this->assertSame('partner:7', $jhun['id']);
        $this->assertSame('CLOSING · M&J', $jhun['team']);
        $this->assertEqualsWithDelta(145966, $totals['partner:7']['sales'], 0.001);
        $this->assertEqualsWithDelta(3377.17, $totals['partner:7']['net'], 0.001);
        $mari = $cards->firstWhere('name', 'SH Mari');
        $this->assertCount(2, $mari['parts']);
        $this->assertEqualsWithDelta(128677, $totals[$mari['id']]['sales'], 0.001);

        // By-month table: Marisol's TSD months (removed, former) join both SH Mari rows.
        $months = fn ($v) => array_fill(1, 12, ['sales' => $v, 'net' => 0.0]);
        $year = fn ($list) => ['rows' => collect($list), 'sheetMonths' => []];
        $merged = \App\Support\LiveTsaBoard::mergeYearFigures(
            $year([$row(3, 'Marisol Lagarde', 'CLOSING', false, true) + ['removed' => true, 'months' => $months(10.0)]]),
            $year([$row('partner:8', 'SH Mari', 'M&J', true) + ['months' => $months(1.0)], $row('partner:9', 'SH Mari', 'PEAK.CO', true) + ['months' => $months(2.0)]])
        );
        $this->assertCount(1, $merged['rows']);
        $this->assertSame('Marisol Lagarde', $merged['rows'][0]['name']);
        $this->assertFalse($merged['rows'][0]['former']);
        $this->assertEqualsWithDelta(13 * 12, $merged['rows'][0]['year']['sales'], 0.001);
    }

    public function test_scoreboard_active_and_former_filter(): void
    {
        $this->admin();
        \App\Models\ScoreboardImport::query()->delete();
        TsaShift::create(['tsa_key' => 'STILLHERE', 'display_name' => 'Still Here', 'team' => 'SH Naturals', 'active' => true]);
        $left = TsaShift::create(['tsa_key' => 'LEFTUS', 'display_name' => 'Left Us', 'team' => 'SH Naturals', 'active' => true]);
        $at = now()->startOfMonth()->addHours(10);
        Order::factory()->create(['tsa_name' => 'LEFTUS', 'team' => 'SH Naturals', 'is_upsell' => true, 'amount' => 1000, 'status_code' => 1,
            'disposition' => 'Upsell w/ Confirmation', 'pancake_created_at' => $at, 'pancake_inserted_at' => $at]);
        $left->forceFill(['last_day' => now()->toDateString()])->save();
        $left->delete();

        $this->get(route('archive.live-tsa', ['team' => 'sh-naturals', 'show' => 'former']))->assertOk()
            ->assertSee('Left Us')->assertDontSee('Still Here');
        $this->get(route('archive.live-tsa', ['team' => 'sh-naturals']))->assertOk() // remembered
            ->assertSee('Left Us')->assertDontSee('Still Here');
        $this->get(route('archive.live-tsa', ['team' => 'sh-naturals', 'show' => 'active']))->assertOk()
            ->assertSee('Still Here')->assertDontSee('Left Us');
    }

    public function test_scoreboard_name_matching_for_moved_tsas(): void
    {
        $same = fn ($a, $b) => \App\Support\LiveTsaBoard::sameName($a, $b);
        $this->assertTrue($same('Lika Trocio', 'Trocio LIKA'));
        $this->assertTrue($same('Jhun Ematong', 'Jhun'));
        $this->assertTrue($same('ANNE PASCUA', 'ANNE'));
        $this->assertFalse($same('Gemma De Guzman', 'Joana'));
        $this->assertFalse($same('Kathleen Santilleses', 'Katherine Chua'));
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

        // restored in TSA Management (e.g. back from a partner) → active again
        $shift->restore();
        \App\Support\ArchiveRosterSync::run();
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNull($e->fresh()->left_at);

        // …but a person set Terminated by hand on People stays former
        $e->update(['employment_status' => 'terminated', 'status' => 'resigned']);
        \App\Support\ArchiveRosterSync::run();
        $this->assertSame('resigned', $e->fresh()->status);
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

        $this->get(route('archive.org-chart', ['mode' => 'arrange']))->assertOk()->assertSee('data-x="420" data-y="180"', false);

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
        $this->get(route('archive.org-chart', ['mode' => 'arrange']))->assertOk()
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

    public function test_org_chart_builds_itself_team_then_head_then_members(): void
    {
        $this->admin();
        $root = Department::create(['name' => 'TSD Telesales', 'color' => 'sky']);
        $closing = Department::create(['name' => 'Team Closing', 'color' => 'amber', 'parent_id' => $root->id]);
        $boss = Employee::create(['full_name' => 'Boss Manager', 'status' => 'active', 'department_id' => $root->id]);
        $root->update(['head_employee_id' => $boss->id]);
        $head = Employee::create(['full_name' => 'Mariel Entanto', 'status' => 'active', 'department_id' => $closing->id, 'manager_id' => $boss->id]);
        $closing->update(['head_employee_id' => $head->id]);
        $tsa = Employee::create(['full_name' => 'Gemma Agent', 'status' => 'active', 'department_id' => $closing->id]); // no manager set
        $loose = Employee::create(['full_name' => 'Loose Person', 'status' => 'active']);
        // a loop in old data must not hang or hide anyone
        $a = Employee::create(['full_name' => 'Loop A', 'status' => 'active', 'department_id' => $closing->id]);
        $b = Employee::create(['full_name' => 'Loop B', 'status' => 'active', 'department_id' => $closing->id, 'manager_id' => $a->id]);
        $a->update(['manager_id' => $b->id]);

        $tree = \App\Support\OrgStructure::build();
        $top = collect($tree['roots'])->first(fn ($n) => $n['model']->id === $root->id && $n['type'] === 'team');
        $this->assertSame('TSD Telesales', $top['model']->name);
        $this->assertSame($boss->id, $top['children'][0]['model']->id);                    // team → its head
        $sub = collect($top['children'][0]['children'])->firstWhere('type', 'team');
        $this->assertSame($closing->id, $sub['model']->id);                                 // sub-team under the parent's head
        $mariel = $sub['children'][0];
        $this->assertTrue($mariel['isHead']);                                               // head first, under her team (not under Boss)
        $names = collect($mariel['children'])->map(fn ($n) => $n['model']->full_name);
        $this->assertContains('Gemma Agent', $names);                                       // no manager → under the team head
        $flat = function ($nodes) use (&$flat) {
            return collect($nodes)->flatMap(fn ($n) => [$n['model']->id . $n['type'], ...$flat($n['children'])])->all();
        };
        $all = collect($flat($tree['roots']));
        $this->assertTrue($all->contains($a->id . 'person') && $all->contains($b->id . 'person'));
        $this->assertSame([$loose->id], $tree['unassigned']->pluck('id')->all());

        $this->get(route('archive.org-chart'))->assertOk()
            ->assertSeeInOrder(['TSD Telesales', 'Boss Manager', 'Team Closing', 'Mariel Entanto', 'Gemma Agent'])
            ->assertSee('Not in a team yet')->assertSee('Loose Person');
        $this->get(route('archive.org-chart', ['mode' => 'arrange']))->assertOk()->assertSee('Done arranging');
    }

    public function test_set_head_add_members_move_and_remove_from_team(): void
    {
        $this->admin();
        $team = Department::create(['name' => 'Team Opening', 'color' => 'sky']);
        $other = Department::create(['name' => 'QA', 'color' => 'rose']);
        $old = Employee::create(['full_name' => 'Old Head', 'status' => 'active', 'department_id' => $team->id]);
        $team->update(['head_employee_id' => $old->id]);
        $tsa = Employee::create(['full_name' => 'Tsa One', 'status' => 'active', 'department_id' => $team->id, 'manager_id' => $old->id]);
        $new = Employee::create(['full_name' => 'New Head', 'status' => 'active']);
        $x = Employee::create(['full_name' => 'Xena', 'status' => 'active']);
        $y = Employee::create(['full_name' => 'Yuri', 'status' => 'active']);
        $gone = Employee::create(['full_name' => 'Gone', 'status' => 'resigned', 'left_at' => '2026-01-01']);

        // make head: joins the team, old head's people now report to the new head
        $this->post(route('archive.departments.head', $team), ['employee_id' => $new->id])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($new->id, $team->fresh()->head_employee_id);
        $this->assertSame($team->id, $new->fresh()->department_id);
        $this->assertSame($new->id, $tsa->fresh()->manager_id);
        $this->post(route('archive.departments.head', $team), ['employee_id' => $gone->id])->assertSessionHasErrors('employee_id');

        // add members: they join and report to the head
        $this->post(route('archive.departments.members', $team), ['employee_ids' => [$x->id, $y->id]])->assertRedirect();
        $this->assertSame([$team->id, $new->id], [$x->fresh()->department_id, $x->fresh()->manager_id]);
        $this->assertSame($new->id, $y->fresh()->manager_id);
        $this->post(route('archive.departments.members', $team), ['employee_ids' => []])->assertSessionHasErrors('employee_ids');

        // move: new team + manager; a loop is refused
        $this->patch(route('archive.people.move', $new), ['department_id' => $team->id, 'manager_id' => $x->id])->assertSessionHasErrors('manager_id');
        $this->patch(route('archive.people.move', $x), ['department_id' => $other->id, 'manager_id' => null])->assertRedirect();
        $this->assertSame([$other->id, null], [$x->fresh()->department_id, $x->fresh()->manager_id]);
        // moving the head out of their team leaves the team without a head
        $this->patch(route('archive.people.move', $new), ['department_id' => $other->id])->assertRedirect();
        $this->assertNull($team->fresh()->head_employee_id);

        // remove from team: reports move up, person stays in People
        $this->post(route('archive.departments.head', $team), ['employee_id' => $old->id]);
        $tsa->fresh()->update(['manager_id' => $old->id]);
        $this->patch(route('archive.people.remove-from-team', $old))->assertRedirect();
        $this->assertSame([null, null, 'active'], [$old->fresh()->department_id, $old->fresh()->manager_id, $old->fresh()->status]);
        $this->assertNull($team->fresh()->head_employee_id);
        $this->assertNull($tsa->fresh()->manager_id);

        $this->get(route('archive.departments'))->assertOk()->assertSee('Pick head')->assertSee('+ Add member', false);
    }

    public function test_setup_guide_shows_until_teams_people_and_heads_exist(): void
    {
        $this->admin();
        TsaShift::query()->delete(); // no roster: the pages' own sync adds nobody
        $this->get(route('archive.org-chart'))->assertSee('Set up your org chart in 3 steps');
        $team = Department::create(['name' => 'Solo', 'color' => 'sky']);
        $p = Employee::create(['full_name' => 'Only One', 'status' => 'active', 'department_id' => $team->id]);
        $this->get(route('archive.departments'))->assertSee('1 team still without a head.');
        $team->update(['head_employee_id' => $p->id]);
        $this->get(route('archive.org-chart'))->assertOk()->assertDontSee('Set up your org chart in 3 steps');
    }
    public function test_partner_tsas_sync_into_team_partners_sub_teams(): void
    {
        $this->admin();
        $this->artisan('migrate', ['--database' => \App\Support\DataSet::PARTNERS]);
        $handMade = Department::create(['name' => 'TEAM PARTNERS']); // upper case: Postgres LIKE is case-sensitive
        $mover = TsaShift::create(['tsa_key' => 'MOVER', 'display_name' => 'Mover Person', 'team' => 'SH Naturals', 'active' => true]);
        $leaver = TsaShift::create(['tsa_key' => 'LEAVER', 'display_name' => 'Gemma Leaver', 'team' => 'SH Naturals', 'active' => true]);
        \App\Support\ArchiveRosterSync::run();
        $mover->delete();
        $leaver->delete();
        \App\Support\DataSet::onPartners(function () {
            TsaShift::create(['tsa_key' => 'M&J:MOVER', 'display_name' => 'Mover Person', 'team' => 'M&J', 'active' => true]);
            TsaShift::create(['tsa_key' => 'PEAK.CO:JOANA', 'display_name' => 'Joana Peak', 'team' => 'PEAK.CO', 'active' => true]);
            // Removed on the partner side: must not become a person or move anyone.
            TsaShift::create(['tsa_key' => 'M&J:Gemma', 'display_name' => 'Gemma Leaver', 'team' => 'M&J', 'active' => true])->delete();
            TsaShift::create(['tsa_key' => 'M&J:Ghost', 'display_name' => 'Ghost Only', 'team' => 'M&J', 'active' => true])->delete();
        });

        \App\Support\ArchiveRosterSync::run();
        \App\Support\ArchiveRosterSync::run(); // twice: the TSD sync must not re-resign the mover

        $this->assertSame('partners', $handMade->fresh()->team_slug);
        $this->assertSame(1, Department::where('name', 'TEAM PARTNERS')->count());
        $peak = Department::where('team_slug', 'partner-peak-co')->first();
        $mj = Department::where('team_slug', 'partner-mj')->first();
        $this->assertSame($handMade->id, $peak->parent_id);
        $joana = Employee::where('partner_tsa_key', 'PEAK.CO:JOANA')->first();
        $this->assertSame($peak->id, $joana->department_id);
        $this->assertSame('active', $joana->status);

        $moved = Employee::where('partner_tsa_key', 'M&J:MOVER')->first();
        $this->assertSame($mover->id, $moved->tsa_shift_id); // same person, not a duplicate
        $this->assertSame($mj->id, $moved->department_id);
        $this->assertSame('active', $moved->status);
        $this->assertSame(1, Employee::where('full_name', 'Mover Person')->count());

        $gemma = Employee::where('tsa_shift_id', $leaver->id)->first();
        $this->assertNull($gemma->partner_tsa_key);
        $this->assertNotSame($mj->id, $gemma->department_id);
        $this->assertSame(0, Employee::where('full_name', 'Ghost Only')->count());

        // Team cards: "Under TEAM PARTNERS", resigned people hidden.
        $this->get(route('archive.departments'))->assertOk()
            ->assertSee('Under')->assertSee('Mover Person')->assertDontSee('Gemma Leaver');

        // A TSA the partner stops sending is resigned.
        \App\Support\DataSet::onPartners(fn () => TsaShift::withoutGlobalScopes()->where('tsa_key', 'PEAK.CO:JOANA')->forceDelete());
        \App\Support\ArchiveRosterSync::run();
        $this->assertSame('resigned', $joana->fresh()->status);
    }
    public function test_tsd_teams_sit_under_team_sh_ecom(): void
    {
        $this->admin();
        Department::query()->delete(); // start from the 2026-11-04 state
        $ecom = Department::create(['name' => 'TEAM SH E-COM', 'archived_at' => now()]);
        $closing = Department::create(['name' => 'CLOSING', 'team_slug' => 'sh-naturals', 'archived_at' => now()]);

        (require database_path('migrations/2026_11_20_000003_nest_tsd_teams_under_sh_ecom.php'))->up();

        $this->assertNull($ecom->fresh()->archived_at);
        $this->assertSame('tsd', $ecom->fresh()->team_slug);
        $this->assertSame($ecom->id, $closing->fresh()->parent_id);
        $this->assertNull($closing->fresh()->archived_at);
        $this->assertSame('SH Naturals', $closing->fresh()->name);

        // the names in use win over the built-in ones (renamed CLOSING)
        \App\Models\TeamNameHistory::create(['slug' => 'sh-naturals', 'name' => 'CLOSING', 'effective_from' => today()->subMonth()]);
        (require database_path('migrations/2026_11_20_000005_use_current_team_names_for_tsd_teams.php'))->up();
        $this->assertSame('CLOSING', $closing->fresh()->name);

        // A selling team the sync creates later also lands under SH E-COM.
        \App\Support\ArchiveRosterSync::run();
        $this->assertSame($ecom->id, Department::where('team_slug', 'eyecare')->value('parent_id'));
        $this->assertSame(1, Department::where('team_slug', 'tsd')->count());
    }

    public function test_head_of_a_parent_team_stops_heading_its_sub_teams(): void
    {
        $this->admin();
        $parent = Department::create(['name' => 'TEAM PARTNERS']);
        $sub = Department::create(['name' => 'PEAK.CO', 'parent_id' => $parent->id]);
        $lhiza = Employee::create(['full_name' => 'Lhiza', 'status' => 'active', 'department_id' => $sub->id]);
        $sub->update(['head_employee_id' => $lhiza->id]);

        $this->post(route('archive.departments.head', $parent), ['employee_id' => $lhiza->id])->assertRedirect();

        $this->assertSame($lhiza->id, $parent->fresh()->head_employee_id);
        $this->assertNull($sub->fresh()->head_employee_id);
        // Chart: TEAM PARTNERS → Lhiza → PEAK.CO
        $root = collect(\App\Support\OrgStructure::build()['roots'])->first(fn ($n) => $n['model']->is($parent));
        $this->assertSame($lhiza->id, $root['children'][0]['model']->id);
        $this->assertTrue($root['children'][0]['children'][0]['model']->is($sub));
    }
    public function test_head_of_parent_and_sub_team_shows_under_the_parent(): void
    {
        $this->admin();
        $parent = Department::create(['name' => 'TEAM PARTNERS']);
        $sub = Department::create(['name' => 'PEAK.CO', 'parent_id' => $parent->id]); // sorts before TEAM PARTNERS
        $lhiza = Employee::create(['full_name' => 'Lhiza', 'status' => 'active', 'department_id' => $parent->id]);
        $parent->update(['head_employee_id' => $lhiza->id]);
        $sub->update(['head_employee_id' => $lhiza->id]); // left over from before

        $root = collect(\App\Support\OrgStructure::build()['roots'])->first(fn ($n) => $n['model']->is($parent));
        $this->assertSame($lhiza->id, $root['children'][0]['model']->id);
        $this->assertTrue($root['children'][0]['headOfTeam']->is($parent));
        $this->assertTrue($root['children'][0]['children'][0]['model']->is($sub));
    }
    public function test_reporting_to_the_parent_teams_head_shows_inside_your_own_team(): void
    {
        $this->admin();
        $ecom = Department::create(['name' => 'TEAM SH E-COM']);
        $closing = Department::create(['name' => 'CLOSING', 'parent_id' => $ecom->id]);
        $gretchen = Employee::create(['full_name' => 'Gretchen', 'status' => 'active', 'department_id' => $ecom->id]);
        $ecom->update(['head_employee_id' => $gretchen->id]);
        $jhun = Employee::create(['full_name' => 'Jhun', 'status' => 'active', 'department_id' => $closing->id, 'manager_id' => $gretchen->id]);

        $root = collect(\App\Support\OrgStructure::build()['roots'])->first(fn ($n) => $n['model']->is($ecom));
        $head = $root['children'][0];
        $this->assertSame($gretchen->id, $head['model']->id);
        $this->assertCount(1, $head['children']); // only CLOSING, not Jhun beside it
        $this->assertTrue($head['children'][0]['model']->is($closing));
        $this->assertSame($jhun->id, $head['children'][0]['children'][0]['model']->id);
    }
    public function test_org_chart_and_teams_pages_run_the_roster_sync(): void
    {
        $this->admin();
        $shift = TsaShift::create(['tsa_key' => 'BACK', 'display_name' => 'Back Again', 'team' => 'SH Naturals', 'active' => true]);
        \App\Support\ArchiveRosterSync::run();
        $e = Employee::firstWhere('tsa_shift_id', $shift->id);
        $e->update(['status' => 'resigned', 'left_at' => today()]); // resigned while away

        $this->get(route('archive.org-chart'))->assertOk()->assertSee('Back Again');
        $this->assertSame('active', $e->fresh()->status);

        $e->update(['status' => 'resigned']);
        $this->get(route('archive.departments'))->assertOk();
        $this->assertSame('active', $e->fresh()->status);
    }
    public function test_teams_can_hang_under_several_people_drawn_as_one_group(): void
    {
        $this->admin();
        TsaShift::query()->delete();
        $tsd = Department::create(['name' => 'TELESALES DEPARTMENT']);
        $allaisa = Employee::create(['full_name' => 'Allaisa', 'position' => 'Manager', 'status' => 'active', 'department_id' => $tsd->id]);
        $tsd->update(['head_employee_id' => $allaisa->id]);
        $raysan = Employee::create(['full_name' => 'Raysan', 'position' => 'AI Engineer', 'status' => 'active', 'department_id' => $tsd->id]);
        $qa = Employee::create(['full_name' => 'QA Person', 'position' => 'QA', 'status' => 'active', 'department_id' => $tsd->id]);
        $partners = Department::create(['name' => 'TEAM PARTNERS', 'parent_id' => $tsd->id]);
        $ecom = Department::create(['name' => 'TEAM SH E-COM', 'parent_id' => $tsd->id]);

        // set through the team form, like a user would
        foreach ([$partners, $ecom] as $team) {
            $this->put(route('archive.departments.update', $team), [
                'name' => $team->name, 'color' => 'sky', 'parent_id' => $tsd->id, 'under_employee_ids' => [$raysan->id, $qa->id],
            ])->assertSessionHasNoErrors();
        }
        $this->assertSame([$raysan->id, $qa->id], $partners->fresh()->under_employee_ids);

        // TELESALES DEPARTMENT → Allaisa → [Raysan + QA] → both teams
        $root = collect(\App\Support\OrgStructure::build()['roots'])->first(fn ($n) => $n['model']->is($tsd));
        $head = $root['children'][0];
        $this->assertSame($allaisa->id, $head['model']->id);
        $this->assertCount(1, $head['children']);
        $group = $head['children'][0];
        $this->assertSame('group', $group['type']);
        $this->assertEqualsCanonicalizing([$raysan->id, $qa->id], array_map(fn ($m) => $m['model']->id, $group['members']));
        $this->assertEqualsCanonicalizing([$partners->id, $ecom->id], array_map(fn ($c) => $c['model']->id, $group['children']));

        $this->get(route('archive.org-chart'))->assertOk()->assertSee('org-group', false)->assertSee('Raysan')->assertSee('TEAM SH E-COM');

        // clearing the ticks puts the team back under the parent team's head
        $this->put(route('archive.departments.update', $ecom), ['name' => $ecom->name, 'color' => 'sky', 'parent_id' => $tsd->id])->assertSessionHasNoErrors();
        $this->assertNull($ecom->fresh()->under_employee_ids);
    }
    public function test_team_cards_count_the_head_and_everyone_in_sub_teams(): void
    {
        $this->admin();
        TsaShift::query()->delete();
        $ecom = Department::create(['name' => 'TEAM SH E-COM']);
        $closing = Department::create(['name' => 'CLOSING', 'parent_id' => $ecom->id]);
        $opening = Department::create(['name' => 'OPENING', 'parent_id' => $ecom->id]);
        $gretchen = Employee::create(['full_name' => 'Gretchen', 'status' => 'active']); // head, in no team
        $ecom->update(['head_employee_id' => $gretchen->id]);
        foreach (['A', 'B', 'C'] as $n) {
            Employee::create(['full_name' => "Closer {$n}", 'status' => 'active', 'department_id' => $closing->id]);
        }
        Employee::create(['full_name' => 'Opener', 'status' => 'active', 'department_id' => $opening->id]);
        Employee::create(['full_name' => 'Gone', 'status' => 'resigned', 'department_id' => $opening->id]);

        $teams = \App\Support\OrgStructure::build()['teams']->keyBy('id');
        $this->assertSame(5, $teams[$ecom->id]->people_total); // Gretchen + 3 + 1
        $this->assertSame(3, $teams[$closing->id]->people_total);
        $this->get(route('archive.org-chart'))->assertOk()->assertSee('5 people');
    }
    public function test_archiving_a_team_warns_what_drops_off_and_can_be_undone(): void
    {
        $this->admin();
        TsaShift::query()->delete();
        $parent = Department::create(['name' => 'TEAM SH E-COM']);
        $sub = Department::create(['name' => 'CLOSING', 'parent_id' => $parent->id]);
        Employee::create(['full_name' => 'Closer', 'status' => 'active', 'department_id' => $sub->id]);

        $this->assertStringContainsString('1 sub-team and 1 person', $parent->archiveConfirm());

        $this->from(route('archive.departments'))->patch(route('archive.departments.archive', $parent))->assertSessionHas('undo');
        $this->assertNotNull($parent->fresh()->archived_at);
        $token = session('undo')['token'];

        $this->post(route('archive.undo', $token))->assertSessionHas('success');
        $this->assertNull($parent->fresh()->archived_at);
        // a token works once
        $this->post(route('archive.undo', $token))->assertSessionHas('error');
    }

    public function test_bulk_move_reports_to_and_status_with_undo(): void
    {
        $this->admin();
        TsaShift::query()->delete();
        $team = Department::create(['name' => 'OPENING']);
        $boss = Employee::create(['full_name' => 'Boss', 'status' => 'active']);
        $a = Employee::create(['full_name' => 'Ann', 'status' => 'active']);
        $b = Employee::create(['full_name' => 'Ben', 'status' => 'active']);

        $this->post(route('archive.people.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'team', 'department_id' => $team->id])->assertSessionHasNoErrors();
        $this->assertSame($team->id, $a->fresh()->department_id);
        $this->assertSame($team->id, $b->fresh()->department_id);
        $this->post(route('archive.undo', session('undo')['token']));
        $this->assertNull($a->fresh()->department_id);

        // reports to: nobody may end up reporting to themselves
        $boss->update(['manager_id' => $a->id]);
        $this->post(route('archive.people.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'manager', 'manager_id' => $boss->id])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->manager_id); // skipped: Boss reports to Ann
        $this->assertSame($boss->id, $b->fresh()->manager_id);

        $this->post(route('archive.people.bulk'), ['ids' => [$a->id], 'action' => 'status', 'status' => 'resigned']);
        $this->assertSame('resigned', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->left_at);
        $this->post(route('archive.people.bulk'), ['ids' => [$a->id], 'action' => 'status', 'status' => 'active']);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertNull($a->fresh()->left_at);

        $this->get(route('archive.people'))->assertOk()->assertSee('data-bulk', false);
    }

    public function test_merging_a_duplicate_moves_everything_and_the_sync_does_not_bring_it_back(): void
    {
        $this->admin();
        $this->artisan('migrate', ['--database' => \App\Support\DataSet::PARTNERS]);
        \App\Support\DataSet::onPartners(function () {
            TsaShift::create(['tsa_key' => 'M&J:Sh', 'display_name' => 'SH Mari', 'team' => 'M&J', 'active' => true]);
            TsaShift::create(['tsa_key' => 'PEAK.CO:Sh', 'display_name' => 'SH Mari', 'team' => 'PEAK.CO', 'active' => true]);
        });
        \App\Support\ArchiveRosterSync::run();
        $keep = Employee::firstWhere('partner_tsa_key', 'M&J:Sh');
        $dup = Employee::firstWhere('partner_tsa_key', 'PEAK.CO:Sh');
        $dup->update(['email' => 'mari@example.test']);
        $report = Employee::create(['full_name' => 'Reports To Dup', 'status' => 'active', 'manager_id' => $dup->id]);
        $team = Department::create(['name' => 'Led by dup', 'head_employee_id' => $dup->id]);

        $this->post(route('archive.people.merge', $keep), ['duplicate_id' => $dup->id])->assertRedirect(route('archive.people.show', $keep));

        $this->assertNull(Employee::find($dup->id));
        $this->assertSame('mari@example.test', $keep->fresh()->email);
        $this->assertSame($keep->id, $report->fresh()->manager_id);
        $this->assertSame($keep->id, $team->fresh()->head_employee_id);

        \App\Support\ArchiveRosterSync::run();
        $this->assertSame(1, Employee::where('full_name', 'SH Mari')->count());
        $this->get(route('archive.people.show', $keep))->assertOk()->assertSee('Merge a duplicate');
    }

    public function test_sidebar_has_three_groups_and_the_chart_can_collapse(): void
    {
        $this->admin();
        $team = Department::create(['name' => 'Folded']);
        $head = Employee::create(['full_name' => 'Head Person', 'status' => 'active', 'department_id' => $team->id]);
        $team->update(['head_employee_id' => $head->id]);
        Employee::create(['full_name' => 'Member Person', 'status' => 'active', 'department_id' => $team->id]);

        $this->get(route('archive.org-chart'))->assertOk()
            ->assertSee('data-collapse', false)->assertSee('Collapse all')->assertSee('Full screen')
            ->assertSeeInOrder(['Overview', 'Team', 'Org Chart', 'Teams', 'People', 'Performance', 'Files', 'Activity Log']);
    }
}
