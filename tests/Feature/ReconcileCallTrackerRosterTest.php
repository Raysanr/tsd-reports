<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileCallTrackerRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_wires_sh_naturals_products_to_their_existing_tsas(): void
    {
        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $gemma = TsaShift::where('tsa_key', 'Gemma')->firstOrFail();

        $this->assertDatabaseHas('product_tsa', [
            'product_id' => $sinuxyl->id,
            'tsa_id'     => $gemma->id,
            'position'   => 0,
        ]);
        $this->assertTrue(RoundRobinState::where('product_id', $sinuxyl->id)->exists());
    }

    /** tsd-reports' existing product is "CLEARSIGHT" (no space);
     *  call-tracker's own seed data calls it "CLEAR SIGHT" (with a space) —
     *  must match via normalized comparison, not exact string equality. */
    public function test_matches_clear_sight_product_name_despite_the_spacing_difference(): void
    {
        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();

        $clearsight = Product::where('display_name', 'CLEARSIGHT')->firstOrFail();
        $this->assertTrue(
            DB::table('product_tsa')->where('product_id', $clearsight->id)->exists(),
            'CLEARSIGHT should have been matched against call-tracker\'s "CLEAR SIGHT" seed despite the spacing difference.'
        );
    }

    /** tsd-reports' baseline tsa_shifts seed only has 6 TSAs — no
     *  "Katherine" — so the Eyecare rotation should wire up the 3 TSAs that
     *  DO exist rather than being skipped entirely for one missing name. */
    public function test_skips_only_the_missing_tsa_not_the_whole_rotation(): void
    {
        $this->assertDatabaseMissing('tsa_shifts', ['tsa_key' => 'Katherine']);

        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();

        $pterygium = Product::where('display_name', 'PTERYGIUM')->firstOrFail();
        $julie = TsaShift::where('tsa_key', 'Julie')->firstOrFail();

        $this->assertDatabaseHas('product_tsa', [
            'product_id' => $pterygium->id,
            'tsa_id'     => $julie->id,
        ]);
    }

    public function test_running_it_twice_does_not_duplicate_product_tsa_rows(): void
    {
        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();
        $firstCount = DB::table('product_tsa')->count();

        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();
        $secondCount = DB::table('product_tsa')->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThan(0, $firstCount);
    }

    public function test_running_it_twice_does_not_duplicate_round_robin_states(): void
    {
        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();
        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();

        $sinuxyl = Product::where('display_name', 'SINUXYL')->firstOrFail();
        $this->assertSame(1, RoundRobinState::where('product_id', $sinuxyl->id)->count());
    }

    public function test_does_not_create_a_new_product_or_tsa_shift_row(): void
    {
        $productCountBefore = Product::count();
        $tsaShiftCountBefore = TsaShift::count();

        $this->artisan('calltracker:reconcile-roster')->assertSuccessful();

        $this->assertSame($productCountBefore, Product::count());
        $this->assertSame($tsaShiftCountBefore, TsaShift::count());
    }
}
