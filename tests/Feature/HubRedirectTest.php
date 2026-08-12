<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HubRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_can_reach_the_hub(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('hub'));

        $response->assertOk();
        $response->assertSee("Seller's Hub", false);
    }

    /** The Hub is a real Blade view now, not a static file passthrough —
     *  this only passes if it actually renders auth()->user() dynamically. */
    public function test_the_hub_greets_the_signed_in_user_by_name(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Dynamic Dana']));

        $response = $this->get(route('hub'));

        $response->assertOk();
        $response->assertSee('Dynamic Dana');
    }

    public function test_the_hubs_sign_out_button_logs_the_user_out(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_hubs_cards_link_to_real_named_routes_when_call_tracker_is_enabled(): void
    {
        config(['services.call_tracker.enabled' => true]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('hub'));

        $response->assertSee(route('dashboard'), false);
        $response->assertSee(route('calls.dashboard'), false);
    }

    /** Explicit request (2026-08-13): show "Coming soon" on the Hub's Call
     *  Tracker card in production until the call-recording storage question
     *  is resolved — the /calls/* routes themselves stay live either way,
     *  this only gates what the Hub advertises. */
    public function test_the_call_tracker_card_shows_coming_soon_when_disabled(): void
    {
        config(['services.call_tracker.enabled' => false]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('hub'));

        $response->assertSee('Coming soon');
        $response->assertDontSee(route('calls.dashboard'), false);
    }

    public function test_call_tracker_routes_stay_reachable_even_when_the_hub_card_is_disabled(): void
    {
        config(['services.call_tracker.enabled' => false]);
        $this->actingAs(User::factory()->create());

        $this->get(route('calls.dashboard'))->assertOk();
    }

    public function test_a_guest_is_redirected_from_the_hub_to_login(): void
    {
        $response = $this->get(route('hub'));

        $response->assertRedirect(route('login'));
    }

    public function test_manual_login_redirects_to_the_hub(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('hub'));
    }

    /** Explicit request (2026-08-12): "sometimes logging in redirects to the
     *  dashboard instead of the Hub." An already-authenticated session
     *  hitting GET /login (bookmark, restored tab, "Keep me signed in" never
     *  expiring) never reaches AuthController at all — the 'guest' route
     *  middleware intercepts first, and Laravel's stock RedirectIfAuthenticated
     *  defaults to whichever of 'dashboard'/'home' has a registered route,
     *  which for this app is 'dashboard' — see AppServiceProvider's
     *  redirectUsing() override, which this proves actually takes effect. */
    public function test_an_already_signed_in_user_visiting_login_lands_on_the_hub_not_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('login'));

        $response->assertRedirect(route('hub'));
    }
}
