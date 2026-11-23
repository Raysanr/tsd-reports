<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $googleId = 'google-123', string $name = 'Jane Doe'): void
    {
        $socialiteUser = \Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($googleId);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.png');

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    }

    public function test_google_sign_in_logs_in_an_existing_matching_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'google_id' => null]);
        $this->fakeGoogleUser('existing@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('hub'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'google-123']);
    }

    public function test_google_sign_in_lands_on_the_hub_even_after_the_guest_first_tried_to_visit_the_dashboard_directly(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'google_id' => null]);
        $this->fakeGoogleUser('existing@example.com');

        $this->get(route('dashboard')); // stores dashboard as the "intended" URL

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('hub'));
    }

    public function test_google_sign_in_does_not_create_an_account_for_an_unmatched_email(): void
    {
        $this->fakeGoogleUser('stranger@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_google_sign_in_rejects_a_deactivated_account(): void
    {
        User::factory()->inactive()->create(['email' => 'deactivated@example.com']);
        $this->fakeGoogleUser('deactivated@example.com');

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
    public function test_an_unknown_google_sign_in_becomes_an_access_request_an_admin_can_accept(): void
    {
        $this->fakeGoogleUser('Stranger@Example.com', 'g-999', 'New Person');
        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get(route('google.callback')); // trying again doesn't duplicate
        $this->assertGuest();
        $req = \App\Models\AccessRequest::sole();
        $this->assertSame('stranger@example.com', $req->email);
        $this->assertSame('pending', $req->status);
        $this->assertSame(2, $req->attempts);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('hub.users'))->assertOk()->assertSee('Access requests')->assertSee('stranger@example.com');
        $this->post(route('hub.users.requests.accept', $req), ['role' => 'normal'])->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['email' => 'stranger@example.com', 'role' => 'normal', 'is_active' => true, 'google_id' => 'g-999']);
        $this->assertSame('approved', $req->fresh()->status);

        // accepted: the next Google sign-in goes straight in
        auth()->logout();
        $this->get(route('google.callback'))->assertRedirect(route('hub'));
        $this->assertAuthenticated();
    }

    public function test_a_rejected_access_request_stays_blocked(): void
    {
        $this->fakeGoogleUser('nope@example.com');
        $this->get(route('google.callback'));
        $req = \App\Models\AccessRequest::sole();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post(route('hub.users.requests.reject', $req))->assertSessionHas('success');
        auth()->logout();

        $this->get(route('google.callback'))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your request to access this system was declined. Ask an admin if this is a mistake.']);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
    }

    public function test_only_people_who_manage_users_can_accept_requests(): void
    {
        $this->fakeGoogleUser('someone@example.com');
        $this->get(route('google.callback'));
        $req = \App\Models\AccessRequest::sole();

        $normal = User::factory()->create(['role' => 'normal']);
        $this->actingAs($normal)->post(route('hub.users.requests.accept', $req), ['role' => 'normal'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }
}
