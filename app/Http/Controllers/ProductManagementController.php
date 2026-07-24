<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Setting;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ProductManagementController extends Controller
{
    public function index()
    {
        $teamsConfig = config('teams', []);
        $products    = Product::orderBy('sort_order')->get();

        $teamGroups = collect($teamsConfig)->map(function ($team) use ($products) {
            return [
                'name'     => $team['name'],
                'products' => $products->where('team', $team['order_team'])->values(),
            ];
        });

        $unassigned = $products->reject(fn($p) => collect($teamsConfig)->pluck('order_team')->contains($p->team));

        $trashedProducts = Product::onlyTrashed()->orderBy('display_name')->get();

        return view('product-management', compact('teamGroups', 'teamsConfig', 'unassigned', 'trashedProducts'));
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);
        $nextSort = (int) (Product::max('sort_order') ?? 0) + 1;

        $product = Product::create([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
            'sort_order'    => $nextSort,
        ]);

        // New keywords can claim previously unattributable (team-NULL) leads —
        // re-infer immediately so they appear in reports without waiting for a
        // manual command run. Only scans unclaimed team-NULL rows, so it's cheap.
        \Artisan::call('orders:reinfer-teams');

        $message = "Added \"{$data['display_name']}\".";
        ActivityLogger::log('product.created', $product, $message);

        return redirect()->route('product-management')
            ->with('success', $message);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        $product->update([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
        ]);

        // Same reasoning as store() — an added alias should immediately pull the
        // matching team-NULL leads into this team's reports.
        \Artisan::call('orders:reinfer-teams');

        $message = "Updated \"{$data['display_name']}\".";
        ActivityLogger::log('product.updated', $product, $message);

        return redirect()->route('product-management')
            ->with('success', $message);
    }

    public function destroy(Product $product)
    {
        $name = $product->display_name;
        $product->delete();

        $message = "Removed \"{$name}\".";
        ActivityLogger::log('product.deleted', $product, $message);

        return redirect()->route('product-management')
            ->with('success', $message);
    }

    // Plain {id} param (not {product}) is deliberate — implicit route-model-binding
    // excludes soft-deleted rows by default, so a {product}-typed param would 404
    // on exactly the trashed records this route needs to find. Resolved manually.
    public function restore(int $id)
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        $message = "Restored \"{$product->display_name}\".";
        ActivityLogger::log('product.restored', $product, $message);

        return redirect()->route('product-management')
            ->with('success', $message);
    }

    public function toggleHidden(Product $product)
    {
        $product->is_hidden = !$product->is_hidden;
        $product->save();

        $verb = $product->is_hidden ? 'Hidden' : 'Unhidden';

        return redirect()->route('product-management')
            ->with('success', "{$verb} \"{$product->display_name}\".");
    }

    public function bulk(Request $request)
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        $data = $request->validate([
            'ids'      => 'required|array|min:1',
            'ids.*'    => 'integer|exists:products,id',
            'action'   => 'required|in:hide,unhide,delete,move',
            'team'     => 'required_if:action,move|nullable|string|in:' . implode(',', $validTeams),
        ]);

        $count = count($data['ids']);
        $noun  = \Illuminate\Support\Str::plural('product', $count);

        switch ($data['action']) {
            case 'hide':
                Product::whereIn('id', $data['ids'])->update(['is_hidden' => true]);
                $message = "Hid {$count} {$noun}.";
                break;
            case 'unhide':
                Product::whereIn('id', $data['ids'])->update(['is_hidden' => false]);
                $message = "Unhid {$count} {$noun}.";
                break;
            case 'move':
                Product::whereIn('id', $data['ids'])->update(['team' => $data['team']]);
                // Same reasoning as store()/update() — a team change can affect which
                // team-NULL leads this product's keywords now claim.
                \Artisan::call('orders:reinfer-teams');
                $teamName = collect($teamsConfig)->firstWhere('order_team', $data['team'])['name'] ?? $data['team'];
                $message = "Moved {$count} {$noun} to {$teamName}.";
                break;
            case 'delete':
                Product::whereIn('id', $data['ids'])->delete();
                $message = "Removed {$count} {$noun}.";
                break;
        }

        // One entry per bulk operation, not one per affected row — that would be noisy.
        // No single subject (it affected multiple rows), so subject is null.
        ActivityLogger::log("product.bulk_{$data['action']}", null, $message);

        return redirect()->route('product-management')->with('success', $message);
    }

    /** AJAX — search the real Pancake product catalog for the "Match keywords"
     *  picker, so a keyword is picked from what POS actually calls a product
     *  instead of free-typed/guessed. Same pattern as TsaManagementController's
     *  Pancake-tags picker. */
    public function searchPosProducts(Request $request): JsonResponse
    {
        $query    = trim((string) $request->input('q', ''));
        $products = $this->fetchPosProducts();

        if ($query !== '') {
            $products = $products->filter(fn($p) => stripos($p['name'], $query) !== false)->values();
        }

        return response()->json($products->take(25)->values());
    }

    /**
     * Fetch + cache the shop's full Pancake product catalog (GET /shops/{id}/
     * products) — every product configured in POS, not just ones already
     * synced onto an order. Paginated (92+ entries on this shop already), so
     * every page is walked and merged into one list. Cached like
     * TsaManagementController's fetchShopTags()/fetchPosUsers() (10 min) —
     * cheap enough for a picker, not meant to be a live product mirror.
     */
    private function fetchPosProducts(): Collection
    {
        return Cache::remember('pancake_shop_products', 600, function () {
            $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
            $shopId = Setting::get('shop_id', '');

            if (empty($apiKey) || empty($shopId)) {
                return collect();
            }

            $url     = "https://pos.pages.fm/api/v1/shops/{$shopId}/products";
            $results = collect();
            $page    = 1;

            while ($page <= 20) {
                $response = Http::withHeaders(['Accept' => 'application/json'])
                    ->timeout(20)
                    ->get($url, ['api_key' => $apiKey, 'page_size' => 100, 'page_number' => $page]);

                if (!$response->successful()) break;

                $data = $response->json('data', []);
                if (empty($data)) break;

                foreach ($data as $p) {
                    $name = trim((string) ($p['name'] ?? ''));
                    if ($name === '') continue;
                    $results->push(['name' => $name, 'id' => $p['id'] ?? null]);
                }

                if (count($data) < 100) break; // last page
                $page++;
            }

            return $results->unique(fn($p) => strtoupper($p['name']))->sortBy('name')->values();
        });
    }

    private function validateProduct(Request $request): array
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        return $request->validate([
            'display_name'  => 'required|string|max:150',
            'match_keyword' => 'nullable|string|max:500',
            'team'          => 'required|string|in:' . implode(',', $validTeams),
        ]);
    }
}
