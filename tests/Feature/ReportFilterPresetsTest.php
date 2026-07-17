<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Lightweight presence checks for the "Presets" dropdown markup (saved filter
// presets — resources/views/partials/filter-presets.blade.php, driven by the
// shared handlers in resources/js/app.js's "Saved filter presets" section)
// added to each report's topbar-right filter form. Mirrors the presence-test
// style of ReportTableSortFilterTest.php — not a JS behavior test (no JS test
// runner exists in this project), just proof the trigger + its data
// attributes render with the correct, page-specific preset key and base URL.
class ReportFilterPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_leads_report_has_presets_dropdown_markup(): void
    {
        $response = $this->get(route('leads-report'));

        $response->assertOk();
        $response->assertSee('data-preset-key="leads-report"', false);
        $response->assertSee('data-preset-base-url="' . route('leads-report') . '"', false);
    }

    public function test_tsa_performance_has_presets_dropdown_markup(): void
    {
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertSee('data-preset-key="tsa-performance"', false);
        $response->assertSee('data-preset-base-url="' . route('tsa-performance') . '"', false);
    }

    public function test_rts_report_has_presets_dropdown_markup(): void
    {
        $response = $this->get(route('rts-report'));

        $response->assertOk();
        $response->assertSee('data-preset-key="rts-report"', false);
        $response->assertSee('data-preset-base-url="' . route('rts-report') . '"', false);
    }
}
