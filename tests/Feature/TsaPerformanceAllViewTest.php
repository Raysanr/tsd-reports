<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceAllViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // Coverage gap found while extending dark mode to the report pages (Part 2a):
    // every other TSA Performance/Leads Report view already had a Feature test
    // hitting it, but team=all — which renders tsa-performance-all.blade.php
    // instead of tsa-performance.blade.php (see TsaPerformanceController::indexAll)
    // — did not. Just proves the page renders; not a styling-specific test.
    public function test_team_all_renders_the_all_tsas_view_without_error(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewIs('tsa-performance-all');
    }
}
