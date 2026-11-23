<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hub announcements board (2026-11-23). */
class HubAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_posts_and_everyone_sees_it_on_the_hub(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('hub.announcements.store'), [
            'title' => 'New product launch', 'body' => "Monday 9 AM\nBring your spiel.", 'important' => '1',
        ])->assertRedirect(route('hub'));

        $this->actingAs(User::factory()->normal()->create())->get(route('hub'))->assertOk()
            ->assertSee('New product launch')->assertSee('Important')->assertDontSee('+ Post announcement');
        $this->actingAs($admin)->get(route('hub'))->assertSee('+ Post announcement');
    }

    public function test_expired_ones_disappear_and_admins_can_remove(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $old = Announcement::create(['title' => 'Old news', 'ends_on' => today()->subDay()]);
        $live = Announcement::create(['title' => 'Still on', 'ends_on' => today()]);

        $this->actingAs($admin)->get(route('hub'))->assertSee('Still on')->assertDontSee('Old news');
        $this->delete(route('hub.announcements.destroy', $live))->assertRedirect(route('hub'));
        $this->assertNull(Announcement::find($live->id));
    }

    public function test_normal_users_cannot_post_or_remove(): void
    {
        $a = Announcement::create(['title' => 'Keep me']);
        $this->actingAs(User::factory()->normal()->create());
        $this->post(route('hub.announcements.store'), ['title' => 'Nope'])->assertForbidden();
        $this->delete(route('hub.announcements.destroy', $a))->assertForbidden();
        $this->assertSame(1, Announcement::count());
    }
}
