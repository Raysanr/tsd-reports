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

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
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

    public function test_a_signed_in_user_visiting_login_is_redirected_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }
}
