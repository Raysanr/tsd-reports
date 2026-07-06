<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PancakeService
{
    private string $baseUrl = 'https://pos.pages.fm/api/v1';
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = Setting::get('pancake_api_key', config('services.pancake.api_key', ''));
    }

    /**
     * Fetch all orders updated since $sinceTimestamp.
     * Paginates automatically until no more pages are returned.
     *
     * ⚠️  ASSUMPTIONS — confirm with your Pancake API docs/sample payload:
     *   1. Auth header: "x-api-key: {key}" — adjust if Pancake uses "Authorization: Bearer {key}".
     *   2. Endpoint: GET /orders  with query params: page, limit, updated_from.
     *   3. Response shape: { data: [...orders], meta: { current_page, last_page } }
     *   4. Each order has: id, tags (array), total_price (or similar), created_at.
     *
     * Paste a real sample response and I'll align field names exactly.
     */
    public function fetchOrders(?int $sinceTimestamp = null): array
    {
        if (empty($this->apiKey)) {
            Log::warning('PancakeService: API key not configured — skipping fetch.');
            return [];
        }

        $orders  = [];
        $page    = 1;
        $perPage = 50;

        do {
            $params = [
                'page_number' => $page,
                'page_size'   => $perPage,
            ];

            if ($sinceTimestamp) {
                $params['time_from'] = $sinceTimestamp;
            }

            try {
                $response = Http::withHeaders(['Accept' => 'application/json'])
                    ->timeout(30)
                    ->get("{$this->baseUrl}/shops/{$this->shopId}/orders", array_merge($params, [
                        'api_key' => $this->apiKey,
                    ]));

                if (!$response->successful()) {
                    Log::error('PancakeService: API error', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    break;
                }

                $body = $response->json();

                // ⚠️ Adjust these keys to match the real response envelope
                $pageOrders = $body['data'] ?? [];
                $lastPage   = $body['total_pages'] ?? 1;

                $orders = array_merge($orders, $pageOrders);
                $page++;

            } catch (\Throwable $e) {
                Log::error('PancakeService: HTTP exception', ['message' => $e->getMessage()]);
                break;
            }

        } while ($page <= $lastPage);

        return $orders;
    }

    /**
     * Extract the monetary total from a raw order array.
     * ⚠️ Confirm field name: "total_price", "grand_total", "amount", "cod_amount", etc.
     */
    public function extractAmount(array $rawOrder): float
    {
        return (float) (
            $rawOrder['total_price'] ??
            $rawOrder['grand_total'] ??
            $rawOrder['amount']      ??
            $rawOrder['cod_amount']  ??
            0
        );
    }

    /**
     * Extract tags array from a raw order.
     * ⚠️ Confirm field name: "tags", "labels", "order_tags", etc.
     */
    public function extractTags(array $rawOrder): array
    {
        $tags = $rawOrder['tags'] ?? $rawOrder['labels'] ?? $rawOrder['order_tags'] ?? [];

        return array_map(function ($tag) {
            return is_array($tag) ? ($tag['name'] ?? '') : (string) $tag;
        }, $tags);
    }

    /**
     * Extract the order's created-at timestamp.
     * ⚠️ Confirm field name: "created_at", "order_date", "date", etc.
     */
    public function extractCreatedAt(array $rawOrder): ?string
    {
        return $rawOrder['created_at'] ?? $rawOrder['order_date'] ?? $rawOrder['date'] ?? null;
    }
}
