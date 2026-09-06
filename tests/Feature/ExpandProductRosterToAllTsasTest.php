<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Explicit request (2026-09-06): "all of the TSA now will handle or cater
 * all of the product now." Previously each product's round-robin roster
 * (product_tsa) only ever had same-team TSAs — see
 * ReconcileCallTrackerRosterTest for the historical same-team seed this
 * command now expands past.
 */
class ExpandProductRosterToAllTsasTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_a_cross_team_tsa_to_a_product_they_were_never_on(): void
    {
        // Baseline seed only wires SH Naturals TSAs to SH Naturals products —
        // Julie (Eyecare Team) starts with none of them.
        $this->artisan('calltracker:reconcile-roster');

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $julie = TsaShift::where('tsa_key', 'Julie')->firstOrFail();

        $this->assertDatabaseMissing('product_tsa', ['product_id' => $sinuxyl->id, 'tsa_id' => $julie->id]);

        $this->artisan('products:expand-roster-to-all-tsas')->assertSuccessful();

        $this->assertDatabaseHas('product_tsa', ['product_id' => $sinuxyl->id, 'tsa_id' => $julie->id]);
    }

    public function test_appends_new_tsas_after_existing_ones_rather_than_reordering_them(): void
    {
        $this->artisan('calltracker:reconcile-roster');

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();
        $julie = TsaShift::where('tsa_key', 'Julie')->firstOrFail();

        $gemmaPositionBefore = DB::table('product_tsa')
            ->where('product_id', $sinuxyl->id)->where('tsa_id', $gemma->id)->value('position');

        $this->artisan('products:expand-roster-to-all-tsas');

        $gemmaPositionAfter = DB::table('product_tsa')
            ->where('product_id', $sinuxyl->id)->where('tsa_id', $gemma->id)->value('position');
        $juliePosition = DB::table('product_tsa')
            ->where('product_id', $sinuxyl->id)->where('tsa_id', $julie->id)->value('position');

        $this->assertSame($gemmaPositionBefore, $gemmaPositionAfter, 'an existing TSA\'s rotation position must not change');
        $this->assertGreaterThan($gemmaPositionAfter, $juliePosition, 'a newly-added TSA must be appended after existing ones, not inserted ahead of them');
    }

    public function test_is_idempotent_running_it_twice_does_not_duplicate_or_error(): void
    {
        $this->artisan('calltracker:reconcile-roster');

        $this->artisan('products:expand-roster-to-all-tsas')->assertSuccessful();
        $countAfterFirstRun = DB::table('product_tsa')->count();

        $this->artisan('products:expand-roster-to-all-tsas')->assertSuccessful();
        $countAfterSecondRun = DB::table('product_tsa')->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $this->artisan('calltracker:reconcile-roster');

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $julie = TsaShift::where('tsa_key', 'Julie')->firstOrFail();

        $this->artisan('products:expand-roster-to-all-tsas --dry-run')->assertSuccessful();

        $this->assertDatabaseMissing('product_tsa', ['product_id' => $sinuxyl->id, 'tsa_id' => $julie->id]);
    }

    public function test_an_inactive_tsa_is_not_attached_to_anything(): void
    {
        $this->artisan('calltracker:reconcile-roster');

        $julie = TsaShift::where('tsa_key', 'Julie')->firstOrFail();
        $julie->update(['active' => false]);

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();

        $this->artisan('products:expand-roster-to-all-tsas')->assertSuccessful();

        $this->assertDatabaseMissing('product_tsa', ['product_id' => $sinuxyl->id, 'tsa_id' => $julie->id]);
    }

    public function test_ensures_a_round_robin_state_row_exists_for_every_product(): void
    {
        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $this->assertFalse(RoundRobinState::where('product_id', $sinuxyl->id)->exists());

        $this->artisan('products:expand-roster-to-all-tsas')->assertSuccessful();

        $this->assertTrue(RoundRobinState::where('product_id', $sinuxyl->id)->exists());
    }
}
