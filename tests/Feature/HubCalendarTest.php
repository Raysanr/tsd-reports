<?php

namespace Tests\Feature;

use App\Models\HubAgenda;
use App\Models\User;
use App\Support\PhHolidays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Hub calendar (explicit request, 2026-11-17). */
class HubCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ph_holidays_include_fixed_and_moving_dates(): void
    {
        $h = PhHolidays::forYears([2026]);

        $this->assertSame('Bonifacio Day', $h['2026-11-30'][0]['name']);
        $this->assertSame('Good Friday', $h['2026-04-03'][0]['name']);
        $this->assertSame('Maundy Thursday', $h['2026-04-02'][0]['name']);
        $this->assertSame('National Heroes Day', $h['2026-08-31'][0]['name']);
        $this->assertSame('Chinese New Year', $h['2026-02-17'][0]['name']);
    }

    public function test_everyone_sees_the_calendar_but_only_admins_can_edit(): void
    {
        Carbon::setTestNow('2026-11-17 09:00:00');
        HubAgenda::create(['date' => '2026-11-20', 'title' => 'Team huddle', 'color' => 'blue']);

        $normal = User::factory()->create(['role' => 'normal']);
        $page = $this->actingAs($normal)->get(route('hub'))->assertOk();
        $page->assertSee('id="calTile"', false)->assertSee('Team huddle')->assertSee('Bonifacio Day');
        $this->assertFalse($page->viewData('calendar')['canEdit']);

        $this->postJson(route('hub.agendas.store'), ['date' => '2026-11-21', 'title' => 'Nope'])->assertForbidden();
        $this->assertSame(1, HubAgenda::count());
    }

    public function test_an_admin_adds_changes_and_deletes_an_agenda(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->assertTrue($this->get(route('hub'))->viewData('calendar')['canEdit']);

        $id = $this->postJson(route('hub.agendas.store'), ['date' => '2026-11-21', 'title' => 'Payroll cut-off', 'time' => '14:30', 'color' => 'rose'])
            ->assertCreated()->assertJsonPath('agenda.title', 'Payroll cut-off')->json('agenda.id');

        $this->putJson(route('hub.agendas.update', $id), ['date' => '2026-11-22', 'title' => 'Payroll', 'color' => 'teal'])
            ->assertOk()->assertJsonPath('agenda.date', '2026-11-22')->assertJsonPath('agenda.time', null);

        $this->postJson(route('hub.agendas.store'), ['date' => '2026-11-21', 'title' => ''])->assertUnprocessable();
        $this->postJson(route('hub.agendas.store'), ['date' => '2026-11-21', 'title' => 'x', 'color' => 'neon'])->assertUnprocessable();

        $this->deleteJson(route('hub.agendas.destroy', $id))->assertOk();
        $this->assertSame(0, HubAgenda::count());
    }
}
