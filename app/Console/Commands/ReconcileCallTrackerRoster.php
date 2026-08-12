<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (idempotent, safe to re-run) reconciliation for merging
 * call-tracker's round-robin roster into tsd-reports' pre-existing
 * `products`/`tsa_shifts` tables (merge phase 4, 2026-08-12).
 *
 * call-tracker seeded its OWN `products`/`tsas` rows, which this app never
 * had — rather than insert a second, duplicate set of rows, this command
 * matches call-tracker's seed data against tsd-reports' existing rows (by
 * normalized product name, by tsa_key) and wires up `product_tsa` +
 * `round_robin_states` against the EXISTING ids. It never inserts a new
 * Product or TsaShift row — a name/key that doesn't resolve is reported and
 * skipped, so a missing roster entry is visible and fixable (e.g. via
 * Product Management / TSA Management) rather than silently duplicated.
 *
 * Confirmed name mismatch to watch for: call-tracker's product is
 * "CLEAR SIGHT" (with a space); tsd-reports' existing product is
 * "CLEARSIGHT" (no space) — matched here via Product::normalizeForMatch(),
 * not exact string equality.
 */
class ReconcileCallTrackerRoster extends Command
{
    protected $signature = 'calltracker:reconcile-roster';
    protected $description = 'Wire call-tracker\'s round-robin roster (product_tsa, round_robin_states) against tsd-reports\' existing products/tsa_shifts rows';

    /** Ported from call-tracker's create_product_tsa_table seed. */
    private const ROTATIONS = [
        ['team' => 'SH Naturals', 'tsa_keys' => ['Gemma', 'Mariel', 'Kathleen'], 'products' => ['SINUXYL', 'AUDICURE', 'GINSENG SERUM', 'CANPRO JUICE DRINK', 'SCAR CREAM']],
        ['team' => 'Eyecare Team', 'tsa_keys' => ['Julie', 'Joana', 'Marisol', 'Katherine'], 'products' => ['CLEAR SIGHT', 'PTERYGIUM']],
    ];

    public function handle(): int
    {
        $products = Product::withTrashed()->get();
        $tsaShifts = TsaShift::withTrashed()->get()->keyBy('tsa_key');

        foreach (self::ROTATIONS as $rotation) {
            $tsas = collect($rotation['tsa_keys'])
                ->map(function (string $tsaKey) use ($tsaShifts) {
                    if (!$tsaShifts->has($tsaKey)) {
                        $this->warn("Skipping TSA \"{$tsaKey}\" — no matching tsa_shifts row (add it via TSA Management first if it should exist).");
                        return null;
                    }
                    return $tsaShifts->get($tsaKey);
                })
                ->filter()
                ->values();

            if ($tsas->isEmpty()) {
                $this->warn("No TSAs resolved for {$rotation['team']} — skipping its products entirely.");
                continue;
            }

            foreach ($rotation['products'] as $productName) {
                $product = $products->first(fn (Product $p) => Product::normalizeForMatch($p->display_name) === Product::normalizeForMatch($productName));

                if (!$product) {
                    $this->warn("Skipping product \"{$productName}\" — no matching products row found.");
                    continue;
                }

                foreach ($tsas as $position => $tsa) {
                    DB::table('product_tsa')->updateOrInsert(
                        ['product_id' => $product->id, 'tsa_id' => $tsa->id],
                        ['position' => $position, 'updated_at' => now(), 'created_at' => now()]
                    );
                }

                RoundRobinState::firstOrCreate(['product_id' => $product->id]);

                $this->info("Wired \"{$product->display_name}\" to: ".$tsas->pluck('tsa_key')->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
