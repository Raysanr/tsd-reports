<?php

namespace Tests\Feature;

use App\Models\TsaMonthSetting;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\TsaMonths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Shift per month (2026-11-03): a TSA's team / shift hours / rest day /
 *  TikTok flag edited for November never rewrites October's reports. */
class TsaMonthsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gemma(): TsaShift
    {
        return TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
    }

    /** Reads TSAs the way a report for $date does. */
    private function asReportOn(string $date): void
    {
        \App\Support\RequestMemo::flush();
        request()->attributes->remove('tsa_month');
        TsaShift::showDeletedFrom($date);
    }

    public function test_nothing_changes_before_the_first_month_is_added(): void
    {
        $gemma = $this->gemma();
        TsaMonths::write($gemma, ['team' => 'SH Naturals']);

        $this->assertSame([], TsaMonths::existing());
        $this->asReportOn('2026-10-01');
        $this->assertSame('SH Naturals', $this->gemma()->team);
    }

    public function test_editing_november_leaves_october_as_it_was(): void
    {
        $original = $this->gemma()->team;
        $other = $original === 'SH Naturals' ? 'Eyecare Team' : 'SH Naturals';

        TsaMonths::create('2026-11');
        $this->assertSame(['2026-10', '2026-11'], TsaMonths::existing());

        TsaMonths::write($this->gemma(), ['team' => $other, 'tiktok_upsell' => true, 'rest_day_of_week' => 'monday', 'shift_start' => '15:00'], '2026-11');

        $this->asReportOn('2026-10-15');
        $october = $this->gemma();
        $this->assertSame($original, $october->team);
        $this->assertFalse((bool) $october->tiktok_upsell);
        $this->assertTrue(TsaShift::where('team', $original)->where('tsa_key', 'Gemma')->exists());

        $this->asReportOn('2026-11-01');
        $november = $this->gemma();
        $this->assertSame($other, $november->team);
        $this->assertTrue((bool) $november->tiktok_upsell);
        $this->assertSame('monday', $november->rest_day_of_week);
        $this->assertSame('15:00', $november->shift_start);
        $this->assertTrue(TsaShift::where('team', $other)->where('tsa_key', 'Gemma')->exists());

        // A later month without its own set follows November; an earlier one follows October.
        $this->asReportOn('2027-01-10');
        $this->assertSame($other, $this->gemma()->team);
        $this->asReportOn('2026-08-01');
        $this->assertSame($original, $this->gemma()->team);
    }

    public function test_adding_a_month_copies_the_month_before_it(): void
    {
        TsaMonths::create('2026-11');
        TsaMonths::write($this->gemma(), ['team' => 'SH Naturals'], '2026-11');
        TsaMonths::create('2026-12');

        $this->assertSame('SH Naturals', TsaMonthSetting::where('month', '2026-12')->where('tsa_shift_id', $this->gemma()->id)->value('team'));

        TsaMonths::write($this->gemma(), ['team' => 'Eyecare Team'], '2026-12');
        $this->asReportOn('2026-11-20');
        $this->assertSame('SH Naturals', $this->gemma()->team);
    }

    public function test_bulk_writes_and_removal_still_work_with_months(): void
    {
        TsaMonths::create('2026-11');
        $gemma = $this->gemma();

        TsaShift::whereIn('id', [$gemma->id])->update(['display_name' => 'Gemma Renamed']);
        $this->assertSame('Gemma Renamed', $this->gemma()->display_name);

        TsaShift::whereIn('id', [$gemma->id])->delete();
        $this->assertNull(TsaShift::where('tsa_key', 'Gemma')->first());
        $this->assertNotNull(TsaShift::withTrashed()->where('tsa_key', 'Gemma')->first()?->deleted_at);
    }

    public function test_tsa_management_add_shift_per_month_button(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('tsa-management', ['month' => '2026-11']))
            ->assertOk()->assertSee('Add Shift Per Month');

        $this->actingAs($admin)->post(route('tsa-management.months.store'), ['month' => '2026-11'])->assertRedirect();
        $this->assertTrue(TsaMonths::exists('2026-11'));

        $gemma = $this->gemma();
        $other = $gemma->team === 'SH Naturals' ? 'Eyecare Team' : 'SH Naturals';
        $this->actingAs($admin)->put(route('tsa-management.update', $gemma), [
            'display_name' => $gemma->display_name, 'team' => $other, 'shift_month' => '2026-11',
        ])->assertRedirect();

        $this->asReportOn('2026-10-15');
        $this->assertNotSame($other, $this->gemma()->team);
        $this->asReportOn('2026-11-15');
        $this->assertSame($other, $this->gemma()->team);
    }

    public function test_hiding_a_tsa_in_one_month_leaves_the_other_months_alone(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        \Illuminate\Support\Carbon::setTestNow('2026-11-10 09:00:00');
        TsaMonths::create('2026-11');
        $gemma = $this->gemma();

        $this->postJson(route('tsa-management.toggle-hidden', ['tsaShift' => $gemma, 'shift_month' => '2026-11']))
            ->assertOk()->assertJson(['hidden' => true]);

        $hiddenIn = fn (string $month) => (bool) \App\Models\TsaMonthSetting::where('month', $month)->where('tsa_shift_id', $gemma->id)->value('is_hidden');
        $this->assertTrue($hiddenIn('2026-11'));
        $this->assertFalse($hiddenIn('2026-10'));

        TsaMonths::view('2026-10');
        $this->assertFalse(\App\Models\TsaShift::find($gemma->id)->is_hidden);
        TsaMonths::view('2026-11');
        $this->assertTrue(\App\Models\TsaShift::find($gemma->id)->is_hidden);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_new_tsa_stays_off_earlier_months_reports(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-10 09:00:00');
        TsaMonths::create('2026-11');
        $new = \App\Models\TsaShift::create(['tsa_key' => 'newbie', 'display_name' => 'Aguinaldo', 'team' => 'SH Naturals', 'sort_order' => 99]);

        TsaMonths::hideFromEarlierMonths($new);

        \App\Models\TsaShift::showDeletedFrom('2026-10-01');
        $this->assertTrue(\App\Models\TsaShift::find($new->id)->is_hidden);
        \App\Models\TsaShift::showDeletedFrom('2026-11-01');
        $this->assertFalse(\App\Models\TsaShift::find($new->id)->is_hidden);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_october_roster_restore_uses_the_old_teams_and_hides_everyone_else(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-10 09:00:00');
        TsaMonths::create('2026-11');
        $make = fn (string $name, string $team) => \App\Models\TsaShift::create(['tsa_key' => strtolower(strtok($name, ' ')) . 'x', 'display_name' => $name, 'team' => $team, 'sort_order' => 90]);
        $grace = $make('GRACE OLIVO', 'SH Naturals');
        $newbie = $make('Aguinaldo', 'SH Naturals');
        $gemma = $this->gemma();
        \App\Models\TsaMonthSetting::where('tsa_shift_id', $gemma->id)->update(['team' => 'SH Naturals']);

        (require database_path('migrations/2026_11_06_000002_restore_october_2026_tsa_roster.php'))->up();

        $october = fn ($tsa) => \App\Models\TsaMonthSetting::where('month', '2026-10')->where('tsa_shift_id', $tsa->id)->first();
        $this->assertSame('Eyecare Team', $october($gemma)->team);
        $this->assertFalse($october($gemma)->is_hidden);
        $this->assertSame('Eyecare Team', $october($grace)->team);
        $this->assertTrue($october($grace)->is_hidden);
        $this->assertSame('SH Naturals', $october($newbie)->team);
        $this->assertTrue($october($newbie)->is_hidden);
        $this->assertSame('SH Naturals', \App\Models\TsaMonthSetting::where('month', '2026-11')->where('tsa_shift_id', $gemma->id)->value('team'));
        \Illuminate\Support\Carbon::setTestNow();
    }
}
