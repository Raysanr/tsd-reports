<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-10-14): Monitor TSA visible to every TSA (normal
 * users) — page + export only; End Call stays admin-only.
 */
class MonitorNormalUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function normal(): User
    {
        return User::factory()->create(['role' => 'normal']);
    }

    public function test_normal_user_can_view_monitor_and_export(): void
    {
        $user = $this->normal();

        $this->actingAs($user)->get(route('calls.monitor'))->assertOk()->assertSee('Monitor TSA');
        $this->actingAs($user)->get(route('calls.monitor.export'))->assertOk();
    }

    public function test_normal_user_cannot_end_another_tsas_call(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->applyStatusChange(TsaShift::STATUS_CALLING);

        $user = $this->normal();

        $this->actingAs($user)->get(route('calls.monitor'))->assertOk()->assertDontSee('class="monitor-end-call-form', false);
        $this->actingAs($user)->post(route('calls.monitor.end-call', $gemma))->assertForbidden();
        $this->assertSame(TsaShift::STATUS_CALLING, $gemma->fresh()->status);
    }

    public function test_admin_still_sees_end_call(): void
    {
        TsaShift::where('tsa_key', 'Gemma')->first()->applyStatusChange(TsaShift::STATUS_CALLING);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('calls.monitor'))->assertOk()->assertSee('class="monitor-end-call-form', false);
    }
}
