<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // Coverage gap found while extending dark mode to the report pages (Part 2a):
    // rts-report.blade.php had zero Feature test coverage before this. Just
    // proves the page renders for an authenticated user; not a styling-specific
    // test.
    public function test_index_renders_successfully_for_an_authenticated_user(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertViewIs('rts-report');
    }
}
