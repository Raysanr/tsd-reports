<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (idempotent, safe to re-run) roster expansion — explicit request,
 * 2026-09-06: "all of the TSA now will handle or cater all of the product
 * now." Until now, product_tsa (each product's round-robin roster) only ever
 * had same-team TSAs attached — see ReconcileCallTrackerRoster's own seed
 * data, and the checkbox picker in calls/tsa-management/_table.blade.php
 * that used to filter to $tsa->team's own products only. RoundRobinAssigner
 * itself never checked team; it always just pulled from product_tsa, so the
 * per-team restriction was entirely a byproduct of what got attached, not
 * something enforced in the assignment logic.
 *
 * This attaches every ACTIVE TSA to every product they're not already on —
 * appended to the END of that product's existing rotation (same "don't jump
 * ahead of TSAs already waiting their turn" convention
 * TsaManagementController::update() already uses for a newly-checked
 * product), never reordering or detaching an existing row. Products/TSAs
 * still keep their own `team` column afterward — this only changes the
 * round-robin ELIGIBILITY roster, not team-scoped reporting (Leads Report,
 * TSA Performance, Analytics, Charts, Dashboard, Insights all keep working
 * exactly as before, unaffected — explicit scope decision, 2026-09-06).
 */
class ExpandProductRosterToAllTsas extends Command
{
    protected $signature = 'products:expand-roster-to-all-tsas {--dry-run : Report what would change without writing anything}';
    protected $description = 'Attach every active TSA to every product\'s round-robin roster, appended after existing TSAs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $products = Product::all();
        $activeTsas = TsaShift::where('active', true)->orderBy('sort_order')->get();

        if ($activeTsas->isEmpty()) {
            $this->warn('No active TSAs found — nothing to expand.');
            return self::SUCCESS;
        }

        $totalAttached = 0;

        foreach ($products as $product) {
            $existingTsaIds = DB::table('product_tsa')->where('product_id', $product->id)->pluck('tsa_id');
            $missingTsas = $activeTsas->whereNotIn('id', $existingTsaIds);

            if ($missingTsas->isEmpty()) {
                continue;
            }

            $nextPosition = (DB::table('product_tsa')->where('product_id', $product->id)->max('position') ?? -1) + 1;

            foreach ($missingTsas as $tsa) {
                if (!$dryRun) {
                    DB::table('product_tsa')->insert([
                        'product_id' => $product->id,
                        'tsa_id'     => $tsa->id,
                        'position'   => $nextPosition,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $nextPosition++;
                $totalAttached++;
            }

            RoundRobinState::firstOrCreate(['product_id' => $product->id]);

            $verb = $dryRun ? 'Would attach' : 'Attached';
            $this->info("{$verb} \"{$product->display_name}\" -> ".$missingTsas->pluck('tsa_key')->implode(', '));
        }

        if ($totalAttached === 0) {
            $this->info('Every active TSA is already attached to every product — nothing to do.');
        } else {
            $suffix = $dryRun ? ' (dry run — nothing written)' : '';
            $this->info("Done. {$totalAttached} product-TSA pair(s) attached{$suffix}.");
        }

        return self::SUCCESS;
    }
}
