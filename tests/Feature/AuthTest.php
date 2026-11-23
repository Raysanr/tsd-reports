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

    /** Google sign-in only (2026-11-23): the email + password form is gone. */
    public function test_password_sign_in_is_gone(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->get(route('login'))->assertOk()->assertSee('Continue with Google')->assertDontSee('name="password"', false);
        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])->assertStatus(405);
        $this->assertGuest();
    }
}
