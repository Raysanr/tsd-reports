<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: Wrap Up auto-expires to Ready to Call after
 * 1 minute. See ExpireWrapUpStatuses' own doc comment for why this is a
 * deliberate reversal of the 2026-09-01 removal of the same behavior.
 */
class ExpireWrapUpStatusesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_wrap_up_to_ready_to_call_after_one_minute(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => TsaShift::STATUS_WRAP_UP, 'status_changed_at' => now()->subMinutes(2)]);

        Artisan::call('calls:expire-wrap-up-statuses');

        $this->assertSame(TsaShift::STATUS_READY_TO_CALL, $gemma->fresh()->status);
    }

    public function test_leaves_a_wrap_up_tsa_under_one_minute_alone(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => TsaShift::STATUS_WRAP_UP, 'status_changed_at' => now()->subSeconds(30)]);

        Artisan::call('calls:expire-wrap-up-statuses');

        $this->assertSame(TsaShift::STATUS_WRAP_UP, $gemma->fresh()->status);
    }

    public function test_a_tsa_who_already_left_wrap_up_manually_is_untouched(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => TsaShift::STATUS_BREAK, 'status_changed_at' => now()->subMinutes(5)]);

        Artisan::call('calls:expire-wrap-up-statuses');

        $this->assertSame(TsaShift::STATUS_BREAK, $gemma->fresh()->status);
    }

    public function test_records_a_real_status_log_entry(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['status' => TsaShift::STATUS_WRAP_UP, 'status_changed_at' => now()->subMinutes(2)]);

        Artisan::call('calls:expire-wrap-up-statuses');

        $this->assertDatabaseHas('tsa_status_logs', [
            'tsa_id' => $gemma->id,
            'status' => TsaShift::STATUS_READY_TO_CALL,
        ]);
    }
}
