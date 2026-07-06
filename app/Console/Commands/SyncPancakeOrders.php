<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use App\Services\PancakeService;
use App\Services\TagParser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncPancakeOrders extends Command
{
    protected $signature   = 'pancake:sync';
    protected $description = 'Sync orders from the shared Pancake POS shop into the local orders table';

    public function handle(PancakeService $pancake, TagParser $parser): int
    {
        $sinceTime = Setting::get('last_synced');
        $sinceTs   = $sinceTime ? Carbon::parse($sinceTime)->timestamp : null;

        $this->info('Syncing since ' . ($sinceTime ?? 'beginning') . ' …');

        $syncStart = now();

        try {
            $rawOrders = $pancake->fetchOrders($sinceTs);
        } catch (\Throwable $e) {
            Log::error('pancake:sync fetchOrders failed', ['message' => $e->getMessage()]);
            $this->error('API error: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Fetched ' . \count($rawOrders) . ' orders.');

        $upserted = 0;

        foreach ($rawOrders as $rawOrder) {
            try {
                $orderId   = (string) ($rawOrder['id'] ?? null);
                $tags      = $pancake->extractTags($rawOrder);
                $parsed    = $parser->parse($tags);
                $amount    = $pancake->extractAmount($rawOrder);
                $createdAt = $pancake->extractCreatedAt($rawOrder);

                if (!$orderId) {
                    Log::warning('pancake:sync: order missing id', ['raw' => $rawOrder]);
                    continue;
                }

                Order::updateOrCreate(
                    ['pancake_order_id' => $orderId],
                    [
                        'team'               => $parsed['team'],
                        'tsa_name'           => $parsed['tsa_name'],
                        'disposition'        => $parsed['disposition'],
                        'product'            => $parsed['product'],
                        'amount'             => $amount,
                        'raw_tags'           => $tags,
                        'pancake_created_at' => $createdAt ? Carbon::parse($createdAt) : null,
                        'synced_at'          => $syncStart,
                    ]
                );
                $upserted++;

            } catch (\Throwable $e) {
                Log::error('pancake:sync: failed to upsert order', [
                    'order'   => $rawOrder['id'] ?? '?',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Setting::set('last_synced', $syncStart->toIso8601String());
        $this->info("Upserted {$upserted} orders. Last-synced timestamp updated.");
        $this->info('Done.');

        return self::SUCCESS;
    }
}
