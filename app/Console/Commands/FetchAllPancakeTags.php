<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchAllPancakeTags extends Command
{
    protected $signature   = 'pancake:all-tags
                                {--shop-id=  : Pancake shop ID (auto-detected from settings if omitted)}
                                {--batch=20  : Concurrent requests per batch}
                                {--out=      : Optional path to save tag list (e.g. tags.txt)}';
    protected $description = 'Fetch ALL unique tags across every order in Pancake POS';

    private string $apiKey;
    private string $baseUrl = 'https://pos.pages.fm/api/v1';

    public function handle(): int
    {
        $this->apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId       = $this->option('shop-id') ?: Setting::get('shop_id', '');
        $batchSize    = (int) $this->option('batch');
        $outFile      = $this->option('out');

        if (empty($this->apiKey)) {
            $this->error('No API key configured.');
            return self::FAILURE;
        }

        if (empty($shopId)) {
            $this->error('No shop ID found. Save your API key in Settings first to auto-detect it.');
            return self::FAILURE;
        }

        $url = "{$this->baseUrl}/shops/{$shopId}/orders";

        // Step 1 — discover total pages
        $this->info("Discovering total pages…");
        $first = Http::timeout(15)->get($url, [
            'api_key'     => $this->apiKey,
            'page_number' => 1,
            'page_size'   => 100,
        ]);

        if (! $first->successful() || ($first->json('success') === false)) {
            $this->error('API request failed: ' . $first->body());
            return self::FAILURE;
        }

        $totalPages = (int) ($first->json('total_pages') ?? 1);
        $this->info("Total pages : {$totalPages}  (page_size=100)");
        $this->newLine();

        // Collect tags from page 1 immediately
        $allTags = [];
        $this->collectTags($first->json('data') ?? [], $allTags);

        // Step 2 — paginate remaining pages in parallel batches
        $bar = $this->output->createProgressBar($totalPages);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('starting…');
        $bar->start();
        $bar->advance(); // page 1 done

        for ($start = 2; $start <= $totalPages; $start += $batchSize) {
            $end     = min($start + $batchSize - 1, $totalPages);
            $pages   = range($start, $end);

            $responses = Http::pool(function ($pool) use ($pages, $url) {
                foreach ($pages as $p) {
                    $pool->as($p)->timeout(30)->get($url, [
                        'api_key'     => $this->apiKey,
                        'page_number' => $p,
                        'page_size'   => 100,
                    ]);
                }
            });

            foreach ($pages as $p) {
                $resp = $responses[$p] ?? null;
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $this->collectTags($resp->json('data') ?? [], $allTags);
                }
                $bar->advance();
            }

            $bar->setMessage(count($allTags) . ' unique tags so far');
        }

        $bar->setMessage('done!');
        $bar->finish();
        $this->newLine(2);

        // Step 3 — sort and display
        arsort($allTags);

        $this->info('=== ALL UNIQUE TAGS (' . count($allTags) . ' total) ===');
        $this->newLine();

        foreach ($allTags as $tag => $count) {
            $this->line(sprintf('  [%5d]  %s', $count, $tag));
        }

        // Step 4 — save to file if requested
        if ($outFile) {
            $lines = ["tag,count\n"];
            foreach ($allTags as $tag => $count) {
                $lines[] = '"' . str_replace('"', '""', $tag) . '",' . $count . "\n";
            }
            file_put_contents($outFile, implode('', $lines));
            $this->newLine();
            $this->info("Saved to: {$outFile}");
        }

        $this->newLine();
        $this->info('Total unique tags: ' . count($allTags));

        return self::SUCCESS;
    }

    private function collectTags(array $orders, array &$allTags): void
    {
        foreach ($orders as $order) {
            foreach ($order['tags'] ?? [] as $tag) {
                $name = is_array($tag) ? ($tag['name'] ?? json_encode($tag)) : (string) $tag;
                if ($name !== '') {
                    $allTags[$name] = ($allTags[$name] ?? 0) + 1;
                }
            }
        }
    }
}
