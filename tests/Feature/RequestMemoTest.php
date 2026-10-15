<?php

namespace Tests\Feature;

use App\Models\ProjectionCustomRow;
use App\Models\Setting;
use App\Support\RequestMemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** RequestMemo (perf, 2026-10-15) must never serve a stale value. */
class RequestMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_value_is_reused_within_one_request(): void
    {
        $calls = 0;
        RequestMemo::remember('k', function () use (&$calls) { return ++$calls; });
        RequestMemo::remember('k', function () use (&$calls) { return ++$calls; });

        $this->assertSame(1, $calls);
    }

    public function test_any_database_write_flushes_it(): void
    {
        Setting::set('memo_probe', 'old');
        $this->assertSame('old', Setting::get('memo_probe'));

        Setting::set('memo_probe', 'new');
        $this->assertSame('new', Setting::get('memo_probe'), 'a write earlier in the same request must be seen');
    }

    public function test_a_freshly_added_custom_row_is_never_missed(): void
    {
        $before = \App\Support\ExpectedIncomeCalculator::customRowKeys();
        ProjectionCustomRow::create(['key' => 'memo_probe_row', 'label' => 'Probe', 'section' => 'selling', 'sort_order' => 999]);

        $this->assertContains('memo_probe_row', \App\Support\ExpectedIncomeCalculator::customRowKeys());
        $this->assertNotContains('memo_probe_row', $before);
    }

    public function test_a_new_request_starts_empty(): void
    {
        RequestMemo::remember('k2', fn () => 'first');
        $this->app->instance('request', Request::create('/next'));

        $this->assertSame('second', RequestMemo::remember('k2', fn () => 'second'));
    }
}
