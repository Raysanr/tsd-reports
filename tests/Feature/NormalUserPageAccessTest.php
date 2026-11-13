<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use App\Support\NormalUserPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings → Page Access (2026-11-13). */
class NormalUserPageAccessTest extends TestCase
{
    use RefreshDatabase;

    private function normal(): User
    {
        return User::factory()->create(['role' => 'normal', 'tsa_id' => TsaShift::first()->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Default page state: normal pages on, admin pages off. */
    private function defaultPages(): array
    {
        return array_keys(array_filter(NormalUserPages::allPages(), fn ($p) => !$p[2]));
    }

    private function saveAs(User $admin, array $modulesOn, array $pagesOn)
    {
        return $this->actingAs($admin)->post(route('settings.page-access'), [
            'modules' => array_fill_keys($modulesOn, '1'),
            'pages'   => array_fill_keys($pagesOn, '1'),
        ]);
    }

    public function test_defaults_match_todays_access(): void
    {
        $tsa = $this->normal();
        $this->actingAs($tsa)->get('/data/actual-income')->assertOk();
        $this->actingAs($tsa)->get('/data/cost-breakdown')->assertForbidden();
        $this->actingAs($tsa)->get(route('archive.overview'))->assertForbidden();
        $this->assertFalse(NormalUserPages::canSeeModule('archive', $tsa));
        $this->assertTrue(NormalUserPages::moduleOn('calls'));
    }

    public function test_pages_switched_off_are_blocked_and_hidden(): void
    {
        $admin = $this->admin();
        $pages = array_values(array_diff($this->defaultPages(), ['data.actual-income', 'calls.unanswered', 'charts']));
        $this->saveAs($admin, ['leads', 'calls', 'data', 'partners'], $pages)->assertRedirect();

        $tsa = $this->normal();
        $this->actingAs($tsa)->get('/data/actual-income')->assertRedirect(route('hub'));
        $this->actingAs($tsa)->get('/charts')->assertRedirect(route('hub'));
        $this->actingAs($tsa)->get(route('calls.leads.index', ['view' => 'unanswered']))->assertRedirect(route('hub'));
        $this->actingAs($tsa)->getJson('/data/actual-income')->assertForbidden();
        $this->actingAs($tsa)->get(route('calls.leads.index', ['view' => 'callbacks']))->assertOk();

        $page = $this->actingAs($tsa)->get('/data/expected-income')->assertOk()->getContent();
        $this->assertStringNotContainsString('/data/actual-income"', $page);
        $this->assertStringContainsString('/data/expected-income"', $page);

        // Partners pages read the same switches (from the main database).
        $this->actingAs($tsa)->get('/partners/actual-income')->assertRedirect(route('hub'));

        $this->actingAs($admin)->get('/data/actual-income')->assertOk();
    }

    public function test_admin_page_switched_on_is_view_only(): void
    {
        $this->saveAs($this->admin(), ['leads', 'calls', 'data', 'partners'], [...$this->defaultPages(), 'data.product-costs', 'user-management']);

        $tsa = $this->normal();
        $this->actingAs($tsa)->get('/data/product-costs')->assertOk();
        $this->assertStringContainsString('/data/product-costs"', $this->actingAs($tsa)->get('/data/expected-income')->getContent());
        $this->actingAs($tsa)->get(route('user-management'))->assertOk();
        $this->actingAs($tsa)->get(route('hub.users'))->assertOk();

        // Saves stay admin-only.
        $this->actingAs($tsa)->post(route('hub.users.store'), ['name' => 'x'])->assertForbidden();
        // Other admin pages stay closed.
        $this->actingAs($tsa)->get('/data/cost-breakdown')->assertForbidden();
    }

    public function test_archiving_can_be_opened_page_by_page(): void
    {
        $this->saveAs($this->admin(), ['leads', 'calls', 'data', 'partners', 'archive'], [...$this->defaultPages(), 'archive.org-chart']);

        $tsa = $this->normal();
        $this->assertTrue(NormalUserPages::canSeeModule('archive', $tsa));
        $this->actingAs($tsa)->get(route('archive.org-chart'))->assertOk();
        $this->actingAs($tsa)->get(route('archive.people'))->assertForbidden();

        $hub = $this->actingAs($tsa)->get(route('hub'))->getContent();
        $this->assertStringContainsString(route('archive.org-chart'), $hub);
    }

    public function test_modules_can_be_switched_off(): void
    {
        $admin = $this->admin();
        $this->saveAs($admin, ['leads', 'calls'], $this->defaultPages());

        $tsa = $this->normal();
        $this->assertFalse($tsa->canAccessModule('data'));
        $this->assertTrue($tsa->canAccessModule('calls'));
        $this->actingAs($tsa)->get('/data/expected-income')->assertRedirect(route('hub'));
        $this->actingAs($tsa)->get('/partners/expected-income')->assertRedirect(route('hub'));
        $this->actingAs($tsa)->get(route('calls.leads.index'))->assertOk();

        $hub = $this->actingAs($tsa)->get(route('hub'))->assertOk()->getContent();
        $this->assertStringNotContainsString('TSD Data Management', $hub);
        $this->assertStringContainsString('TSD Call Tracker', $hub);

        $this->saveAs($admin, ['calls', 'data', 'partners'], $this->defaultPages());
        $this->actingAs($tsa)->get('/leads-report')->assertRedirect(route('hub'));
        $this->actingAs($tsa)->get(route('settings'))->assertOk();
        $this->actingAs($tsa)->get(route('messages.inbox'))->assertOk();

        $this->actingAs($admin)->get('/data/expected-income')->assertOk();
        $this->assertTrue($admin->canAccessModule('leads'));
    }

    public function test_normal_users_cannot_change_page_access(): void
    {
        $this->actingAs($this->normal())->post(route('settings.page-access'), ['modules' => [], 'pages' => []])->assertForbidden();
        $this->assertTrue(NormalUserPages::moduleOn('leads'));
    }

    public function test_data_home_lands_on_first_visible_page(): void
    {
        $this->saveAs($this->admin(), ['leads', 'calls', 'data', 'partners'],
            array_values(array_diff($this->defaultPages(), ['data.projections', 'data.dsppr'])));
        $this->actingAs($this->normal())->get('/data')->assertRedirect(route('data.tsa-sales'));
    }

    public function test_settings_panel_renders_every_module(): void
    {
        $html = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        foreach (NormalUserPages::MODULE_LABELS as $label) {
            $this->assertStringContainsString(e($label), $html);
        }
        $this->assertStringContainsString('name="pages[archive.people]"', $html);
        $this->assertStringContainsString('name="pages[data.cost-breakdown]"', $html);
    }
}
