<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DmMonthLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lock closed months in Data Management (2026-11-23). */
class DataManagementMonthLockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u);

        return $u;
    }

    public function test_admin_locks_a_finished_month_and_saves_in_it_are_refused(): void
    {
        $this->admin();
        $last = now()->subMonthNoOverflow()->format('Y-m');

        $this->get(route('data.month-locks'))->assertOk()->assertSee(DmMonthLock::label($last));
        $this->post(route('data.month-locks.store'), ['month' => $last])->assertSessionHas('success');
        $this->assertTrue(DmMonthLock::isLocked($last));

        // a save dated in that month: refused with the reason
        $this->patchJson(route('data.tsa-sales.toggle-lock', ['date' => $last . '-05']))
            ->assertStatus(423)->assertJsonFragment(['success' => false])
            ->assertJson(fn ($j) => $j->where('message', DmMonthLock::label($last) . ' is locked. Ask an admin to unlock it in Month locks before changing it.')->etc());

        // a session-month page (Projections) on the locked month: refused too
        $this->withSession(['projections.month' => $last])
            ->patchJson(route('data.projections.update-rates'), ['key' => 'x', 'value' => 1])->assertStatus(423);

        // other months still save normally (whatever the page itself answers, it isn't the lock)
        $this->assertNotSame(423, $this->patchJson(route('data.tsa-sales.toggle-lock', ['date' => now()->format('Y-m') . '-01']))->status());

        // other Data Management pages show the locked banner
        $this->get(route('data.dsppr'))->assertOk()->assertSee('Locked (read-only)')->assertSee(DmMonthLock::label($last));
        $this->delete(route('data.month-locks.destroy'), ['month' => $last])->assertSessionHas('success');
        $this->assertFalse(DmMonthLock::isLocked($last));
    }

    public function test_the_current_month_cannot_be_locked_and_normal_users_cannot_manage_locks(): void
    {
        $this->admin();
        $this->post(route('data.month-locks.store'), ['month' => now()->format('Y-m')])->assertStatus(422);

        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('data.month-locks'))->assertForbidden();
        $this->post(route('data.month-locks.store'), ['month' => now()->subMonthNoOverflow()->format('Y-m')])->assertForbidden();
    }

    public function test_month_of_reads_the_route_date_model_input_and_session(): void
    {
        $r = \Illuminate\Http\Request::create('/data/x', 'PATCH', ['month' => '2026-09']);
        $this->assertSame('2026-09', \App\Http\Middleware\DataManagementMonthLock::monthOf($r));
        $this->assertNull(\App\Http\Middleware\DataManagementMonthLock::monthOf(\Illuminate\Http\Request::create('/data/x', 'PATCH')));
    }
}
