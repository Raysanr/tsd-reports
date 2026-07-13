<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Console\Command;

class ReinferOrderTeams extends Command
{
    protected $signature   = 'orders:reinfer-teams {--dry-run : Report what would change without writing}';
    protected $description = 'Re-run team inference over unclaimed team-NULL orders using current product keywords — run after adding/renaming product match keywords so previously invisible leads join their team\'s reports';

    public function handle(): int
    {
        $products = Product::orderBy('sort_order')->get();
        $dryRun   = $this->option('dry-run');

        // Only unclaimed leads (no TSA) — an order WITH a tsa_name gets its team
        // from the TSA's roster entry at sync time, never from product inference,
        // so re-inferring those here could only ever disagree with the roster.
        // No API calls: the cart product name and tags are already stored locally.
        $updated = 0;
        $byTeam  = [];

        Order::whereNull('team')->whereNull('tsa_name')
            ->chunkById(500, function ($orders) use ($products, $dryRun, &$updated, &$byTeam) {
                foreach ($orders as $order) {
                    $team = $this->matchTeam($products, $order->product)
                        ?? collect($order->raw_tags ?? [])
                            ->map(fn($tag) => $this->matchTeam($products, $tag))
                            ->first(fn($t) => $t !== null);

                    if ($team === null) continue;

                    if (!$dryRun) {
                        $order->update(['team' => $team]);
                    }
                    $updated++;
                    $byTeam[$team] = ($byTeam[$team] ?? 0) + 1;
                }
            });

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Re-inference complete: {$verb} {$updated} orders.");
        foreach ($byTeam as $team => $count) {
            $this->line("  {$team}: {$count}");
        }

        return self::SUCCESS;
    }

    private function matchTeam($products, ?string $text): ?string
    {
        foreach ($products as $product) {
            if ($product->matchesText($text)) {
                return $product->team;
            }
        }
        return null;
    }
}
