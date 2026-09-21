<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-21, tied to a real same-day incident: Hannah
 * forgot to log out the previous day, stayed in Login overnight absorbing
 * unattended round-robin leads, and the manual cleanup led to the "same
 * lead visible to two TSAs" confusion reported that morning. See
 * LogoutAllTsasAtMidnight's own doc comment for the full mechanics — this
 * only asserts the STATUS side (directly testable in-process); the actual
 * lead redistribution that firing this in production also triggers is
 * already exhaustively covered by LogoutLeadRedistributorTest, whose own
 * doc comment explains why that side can't be exercised the same way here
 * (RedistributeLoggedOutTsaLeads::dispatch()->afterResponse() only ever
 * fires once something calls $app->terminate(), which a single PHPUnit
 * process never does mid-suite — a real console/scheduled invocation goes
 * through that lifecycle normally, see LogoutAllTsasAtMidnight's own doc
 * comment for the full reasoning).
 */
class LogoutAllTsasAtMidnightTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_out_every_tsa_not_already_logged_out(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $gemma->update(['status' => 'login']);
        $mariel->update(['status' => 'calling']);

        Artisan::call('calls:logout-all-tsas-at-midnight');

        $this->assertSame(TsaShift::STATUS_LOGOUT, $gemma->fresh()->status);
        $this->assertSame(TsaShift::STATUS_LOGOUT, $mariel->fresh()->status);
    }

    public function test_an_already_logged_out_tsa_is_left_alone(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $threeHoursAgo = now()->subHours(3);
        $gemma->update(['status' => 'logout', 'status_changed_at' => $threeHoursAgo]);

        Artisan::call('calls:logout-all-tsas-at-midnight');

        // Not just still logout — genuinely untouched, no redundant write
        // that would bump status_changed_at for someone who already signed
        // off on their own.
        $this->assertSame($threeHoursAgo->toDateTimeString(), $gemma->fresh()->status_changed_at->toDateTimeString());
    }

    /**
     * An admin explicitly Locked this TSA into their current status
     * (Pancake's own admin-only conversation-receive-mode equivalent) —
     * a scheduled job silently overriding that deliberate choice would
     * undermine the whole point of Lock existing.
     */
    public function test_a_locked_tsa_is_never_force_logged_out(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => 'locked', 'status_locked_by' => $admin->id]);

        Artisan::call('calls:logout-all-tsas-at-midnight');

        $this->assertSame('locked', $gemma->fresh()->status);
    }

    public function test_does_nothing_when_turned_off_in_tsa_management(): void
    {
        Setting::set('midnight_auto_logout_enabled', false);
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => 'login']);

        Artisan::call('calls:logout-all-tsas-at-midnight');

        $this->assertSame('login', $gemma->fresh()->status);
    }

    public function test_runs_normally_when_the_setting_is_missing_entirely(): void
    {
        // Defaults to enabled — this command exists specifically because a
        // forgotten logout caused a real incident, so an admin who never
        // touches the toggle still gets the protection.
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => 'login']);

        Artisan::call('calls:logout-all-tsas-at-midnight');

        $this->assertSame(TsaShift::STATUS_LOGOUT, $gemma->fresh()->status);
    }
}
