<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_dashboard_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_can_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_register_route_no_longer_exists(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_login_page_has_no_sign_up_link(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('Sign up');
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('hub'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Explicit request: login always lands on the Hub, no exceptions — one
     * real login page shared by both TSD systems. A plain redirect()->
     * intended(route('hub')) breaks this: visiting a protected page (e.g.
     * the dashboard) while logged out stores THAT page as the "intended"
     * destination, and intended() prefers it over the route('hub')
     * fallback on every subsequent login — even after logging out and back
     * in — since nothing ever clears that stored URL. Confirmed live: a
     * user who ever visits the dashboard directly while signed out never
     * lands on the Hub again until intended() is dropped entirely.
     */
    public function test_login_lands_on_the_hub_even_after_the_guest_first_tried_to_visit_the_dashboard_directly(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        // Simulates the guest hitting a protected URL first — this is what
        // stores an "intended" URL in the session that intended() would
        // otherwise prefer over route('hub').
        $this->get(route('dashboard'));

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('hub'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Explicit request: POST /login had no throttling at all — unlimited
     * password guesses against any known/guessed email. throttle:login
     * (5/min, keyed by email+IP — see AppServiceProvider::boot()) now blocks
     * a 6th rapid attempt with a 429, regardless of whether any of the first
     * 5 attempts happened to guess the password correctly.
     */
    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post(route('login'), [
                'email' => $user->email, 'password' => 'wrong-password',
            ]);
            $response->assertSessionHasErrors('email');
        }

        // 6th attempt in under a minute — even with the CORRECT password —
        // is throttled before credentials are ever checked.
        $response = $this->post(route('login'), [
            'email' => $user->email, 'password' => 'secret123',
        ]);

        $response->assertStatus(429);
        $this->assertGuest();
    }

    public function test_rate_limit_is_keyed_by_email_not_just_ip(): void
    {
        $attacked  = User::factory()->create(['password' => Hash::make('secret123')]);
        $bystander = User::factory()->create(['password' => Hash::make('secret456')]);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $attacked->email, 'password' => 'wrong']);
        }

        // Same IP (Laravel's test client), but a DIFFERENT email — a
        // legitimate coworker on a shared office/VPN IP must still be able
        // to sign in even while someone else on that IP is being throttled.
        $response = $this->post(route('login'), [
            'email' => $bystander->email, 'password' => 'secret456',
        ]);

        $response->assertRedirect(route('hub'));
        $this->assertAuthenticatedAs($bystander);
    }

    public function test_login_rejects_a_deactivated_account_even_with_correct_password(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('secret123')]);

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Updated 2026-08-12 — this used to assert the dashboard, which was
     *  the actual bug: "sometimes logging in redirects to the dashboard
     *  instead of the Hub." An already-authenticated session hitting this
     *  route never reaches AuthController (whose own login flows always go
     *  to the Hub) — Laravel's stock RedirectIfAuthenticated guest
     *  middleware intercepts first and defaults to the 'dashboard' route
     *  since one exists. See AppServiceProvider's redirectUsing() override,
     *  which fixes this to agree with every other login path. Duplicated in
     *  HubRedirectTest (that file's own dedicated coverage for Hub
     *  behavior) — kept here too since this is where this exact regression
     *  was previously locked in. */
    public function test_a_signed_in_user_visiting_login_is_redirected_to_the_hub(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('login'));

        $response->assertRedirect(route('hub'));
    }
}
