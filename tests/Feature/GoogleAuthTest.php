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

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'google-123']);
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
}
