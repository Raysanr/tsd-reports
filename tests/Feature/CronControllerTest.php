<?php

namespace Tests\Feature;

use Tests\TestCase;

class CronControllerTest extends TestCase
{
    public function test_missing_token_is_rejected(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run')->assertForbidden();
    }

    public function test_wrong_token_is_rejected(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run?token=wrong')->assertForbidden();
    }

    public function test_no_configured_secret_rejects_every_request(): void
    {
        config(['services.cron.secret' => null]);

        $this->get('/cron/run?token=anything')->assertForbidden();
    }

    public function test_correct_token_runs_the_scheduler(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run?token=the-real-secret')->assertOk();
    }
}
