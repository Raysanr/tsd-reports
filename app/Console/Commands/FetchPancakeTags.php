<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchPancakeTags extends Command
{
    protected $signature   = 'pancake:fetch-tags
                                {--shop-id= : Pancake shop ID (auto-detected from settings if omitted)}
                                {--page=1   : Which page of orders to inspect}';
    protected $description = 'Fetch one page of orders from Pancake POS and list all unique tags';

    private string $apiKey;
    private string $baseUrl = 'https://pos.pages.fm/api/v1';

    public function handle(): int
    {
        $this->apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $shopId       = $this->option('shop-id') ?: Setting::get('shop_id', '');

        if (empty($this->apiKey)) {
            $this->error('No API key. Save it in Settings first.');
            return self::FAILURE;
        }

        if (empty($shopId)) {
            $this->error('No shop ID found. Save your API key in Settings first to auto-detect it.');
            return self::FAILURE;
        }

        $url = "{$this->baseUrl}/shops/{$shopId}/orders";
        $this->info("Endpoint : {$url}");
        $this->info("API key  : …" . substr($this->apiKey, -4));
        $this->newLine();

        $page     = (int) $this->option('page');
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout(30)
            ->get($url, ['api_key' => $this->apiKey, 'page_number' => $page, 'page_size' => 50]);

        $body   = $response->json();
        $orders = $body['data'] ?? [];

        // Top-level envelope
        $this->info('=== RESPONSE ENVELOPE ===');
        foreach ($body as $k => $v) {
            if ($k === 'data') { $this->line("  data  → array of " . \count($v) . " orders"); continue; }
            $this->line("  {$k} → " . (\is_array($v) ? json_encode($v) : $v));
        }
        $this->newLine();

        if (empty($orders)) {
            $this->warn('No orders returned. Raw body:');
            $this->line(json_encode($body, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // First order fields
        $this->info('=== FIRST ORDER FIELD NAMES ===');
        $first = $orders[0];
        foreach ($first as $field => $val) {
            $preview = \is_array($val)
                ? '[array ' . \count($val) . '] ' . mb_strimwidth(json_encode($val), 0, 60, '…')
                : ($val === null ? 'null' : mb_strimwidth((string) $val, 0, 60, '…'));
            $this->line(\sprintf('  %-30s %s', $field, $preview));
        }
        $this->newLine();

        // Tags
        $tags    = $first['tags'] ?? [];
        $tagType = empty($tags) ? 'empty' : (\is_array($tags[0]) ? 'array of objects' : 'array of strings');
        $this->info("=== TAGS FIELD (first order) ===");
        $this->line("  Type    : {$tagType}");
        $this->line("  Raw     : " . json_encode($tags));
        $this->newLine();

        // All unique tags on this page
        $allTags = [];
        foreach ($orders as $order) {
            foreach ($order['tags'] ?? [] as $tag) {
                $name = \is_array($tag) ? ($tag['name'] ?? json_encode($tag)) : (string) $tag;
                if ($name) $allTags[$name] = ($allTags[$name] ?? 0) + 1;
            }
        }
        arsort($allTags);

        $this->info('=== ALL UNIQUE TAGS (' . \count($orders) . ' orders on page ' . $page . ') ===');
        if (empty($allTags)) {
            $this->warn('  No tags found on this page.');
        } else {
            foreach ($allTags as $tag => $count) {
                $this->line(\sprintf('  [%3d]  %s', $count, $tag));
            }
        }

        $this->newLine();
        $this->line("total_pages  : " . ($body['total_pages']   ?? '?'));
        $this->line("total_entries: " . ($body['total_entries'] ?? '?'));

        return self::SUCCESS;
    }
}
