<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DataSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Guests: view-only, limited to the modules picked in User Management (2026-11-04). */
class GuestModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DataSet::reset();
        parent::tearDown();
    }

    private function guest(?array $modules): User
    {
        return User::factory()->create(['role' => 'guest', 'allowed_modules' => $modules]);
    }

    private function guestForm(array $extra = []): array
    {
        return array_merge(['name' => 'Visitor', 'email' => 'visitor@example.com', 'role' => 'guest'], $extra);
    }

    public function test_adding_a_guest_saves_the_picked_modules(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('user-management.store'), $this->guestForm(['modules' => ['leads', 'data']]))
            ->assertRedirect();

        $this->assertSame(['leads', 'data'], User::where('email', 'visitor@example.com')->first()->allowed_modules);
    }

    public function test_a_guest_needs_at_least_one_valid_module(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('user-management.store'), $this->guestForm())->assertSessionHasErrors('modules');
        $this->actingAs($admin)->post(route('user-management.store'), $this->guestForm(['modules' => ['archive']]))->assertSessionHasErrors('modules.0');
        $this->assertDatabaseMissing('users', ['email' => 'visitor@example.com']);
    }

    public function test_changing_a_guest_to_another_role_clears_their_modules(): void
    {
        $guest = $this->guest(['leads']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('user-management.update', $guest), ['name' => $guest->name, 'email' => $guest->email, 'role' => 'normal', 'modules' => ['leads']])
            ->assertRedirect();

        $this->assertNull($guest->fresh()->allowed_modules);
    }

    public function test_the_form_offers_every_module_but_not_the_archive(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('user-management'))
            ->assertOk()->assertSee('Modules this guest can view')->assertSee('value="partners"', false)->assertDontSee('value="archive"', false);
    }

    public function test_a_guest_only_opens_the_picked_modules(): void
    {
        $this->actingAs($this->guest(['data']));

        $this->get(route('data.projections'))->assertOk();
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('leads-report'))->assertForbidden();
        $this->get(route('calls.dashboard'))->assertForbidden();
        $this->get('/partners/projections')->assertForbidden();
    }

    public function test_every_data_management_page_opens_for_a_guest(): void
    {
        $this->actingAs($this->guest(['data']));

        foreach (['data.projections', 'data.dsppr', 'data.tsa-sales', 'data.expected-income', 'data.actual-income', 'data.activity-log'] as $page) {
            $this->get(route($page))->assertOk();
        }
    }

    public function test_data_pages_are_read_only_for_guests_only(): void
    {
        $marker = 'data-guest-static';

        $this->actingAs($this->guest(['data']))->get(route('data.expected-income'))->assertOk()->assertSee($marker, false);
        $this->actingAs(User::factory()->normal()->create())->get(route('data.expected-income'))->assertOk()->assertDontSee($marker, false);
    }

    public function test_the_partners_module_is_separate_from_tsd_data(): void
    {
        $this->artisan('migrate', ['--database' => DataSet::PARTNERS]);
        $this->actingAs($this->guest(['partners']));

        $this->get('/partners/projections')->assertOk();
        DataSet::reset();
        $this->get('/data/projections')->assertForbidden();
    }

    public function test_the_hub_shows_only_the_picked_modules(): void
    {
        $this->actingAs($this->guest(['leads']))->get(route('hub'))
            ->assertOk()->assertSee('TSD Leads Reports')->assertDontSee('TSD Data Management')
            ->assertDontSee('Partners Data Management')->assertDontSee('TSD Call Tracker');
    }

    public function test_guests_cannot_save_anything_even_in_their_own_modules(): void
    {
        $this->actingAs($this->guest(['leads', 'calls', 'data']));

        $this->post(route('calls.dashboard.sync'))->assertForbidden();
        $this->patch('/data/expected-income/overall-label', ['label' => 'Hacked'])->assertForbidden();
        $this->post(route('dashboard.sync'))->assertForbidden();
    }

    public function test_admin_only_pages_stay_closed_to_guests(): void
    {
        $this->actingAs($this->guest(array_keys(User::MODULES)));

        $this->get(route('data.cost-breakdown'))->assertForbidden();
        $this->get(route('data.overall.projections'))->assertForbidden();
        $this->get(route('archive.overview'))->assertForbidden();
        $this->get(route('user-management'))->assertForbidden();
    }

    public function test_guests_from_before_this_feature_keep_what_they_had(): void
    {
        $this->actingAs($this->guest(null));

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('calls.dashboard'))->assertOk();
        $this->get(route('data.projections'))->assertForbidden();
    }

    public function test_guests_can_still_sign_out_and_other_roles_are_unaffected(): void
    {
        $this->actingAs($this->guest(['data']))->post(route('logout'))->assertRedirect();

        $this->actingAs(User::factory()->normal()->create())->get(route('dashboard'))->assertOk();
    }
}
