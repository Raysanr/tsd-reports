<?php

namespace Tests\Unit;

use App\Support\DateRangeFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-30: "why is it when i am clicking other page
 * and then go back why is it resetting ... i want to make it it is first
 * like today only when first open and depend of the user if they will date
 * pick wide range" — a fresh sidebar-link navigation has no query string
 * of its own, so the only way a report page can "remember" a picked range
 * across separate visits is the session, keyed per page.
 */
class DateRangeFilterTest extends TestCase
{
    public function test_a_brand_new_session_defaults_to_today_only(): void
    {
        $range = DateRangeFilter::resolve(Request::create('/'), 'some-page');

        $this->assertSame(today()->toDateString(), $range['from']);
        $this->assertSame(today()->toDateString(), $range['to']);
    }

    public function test_an_explicit_range_on_the_request_wins_and_is_remembered(): void
    {
        $request = Request::create('/', 'GET', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $range = DateRangeFilter::resolve($request, 'some-page');

        $this->assertSame('2026-01-01', $range['from']);
        $this->assertSame('2026-01-31', $range['to']);

        // A later request with NO query string at all falls back to the
        // range just remembered, not the bare "today only" default.
        $laterRange = DateRangeFilter::resolve(Request::create('/'), 'some-page');
        $this->assertSame('2026-01-01', $laterRange['from']);
        $this->assertSame('2026-01-31', $laterRange['to']);
    }

    public function test_each_page_remembers_its_own_range_independently(): void
    {
        DateRangeFilter::resolve(Request::create('/', 'GET', ['date_from' => '2026-02-01', 'date_to' => '2026-02-05']), 'page-a');
        DateRangeFilter::resolve(Request::create('/', 'GET', ['date_from' => '2026-03-01', 'date_to' => '2026-03-10']), 'page-b');

        $pageA = DateRangeFilter::resolve(Request::create('/'), 'page-a');
        $pageB = DateRangeFilter::resolve(Request::create('/'), 'page-b');

        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-05'], $pageA);
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-10'], $pageB);
    }
}
