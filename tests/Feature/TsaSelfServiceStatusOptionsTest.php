<?php

namespace Tests\Feature;

use App\Models\TsaShift;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-24: "the login will only one time ... whe tsa
 * click like break they can only click the ready to call not login." See
 * TsaShift::selfServiceOptionsFor()'s own doc comment for the full
 * reasoning — Login is only offered from Logout; every other status offers
 * Ready to Call instead.
 */
class TsaSelfServiceStatusOptionsTest extends TestCase
{
    public function test_login_is_offered_from_logout(): void
    {
        $options = TsaShift::selfServiceOptionsFor(TsaShift::STATUS_LOGOUT);

        $this->assertContains(TsaShift::STATUS_LOGIN, $options);
    }

    public function test_login_is_not_offered_from_break(): void
    {
        $options = TsaShift::selfServiceOptionsFor(TsaShift::STATUS_BREAK);

        $this->assertNotContains(TsaShift::STATUS_LOGIN, $options);
        $this->assertContains(TsaShift::STATUS_READY_TO_CALL, $options);
    }

    public function test_login_is_not_offered_once_already_logged_in(): void
    {
        $options = TsaShift::selfServiceOptionsFor(TsaShift::STATUS_LOGIN);

        $this->assertNotContains(TsaShift::STATUS_LOGIN, $options);
        $this->assertContains(TsaShift::STATUS_READY_TO_CALL, $options);
    }

    public function test_ready_to_call_is_offered_from_wrap_up(): void
    {
        $options = TsaShift::selfServiceOptionsFor(TsaShift::STATUS_WRAP_UP);

        $this->assertNotContains(TsaShift::STATUS_LOGIN, $options);
        $this->assertContains(TsaShift::STATUS_READY_TO_CALL, $options);
    }
}
