<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Setting;
use App\Support\ActivityLogger;
use App\Support\Teams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ProductManagementController extends Controller
{
    public function index()
    {
        return view('product-management', $this->buildViewData());
    }

    /** Same data as index() above, rendered as a Hub-styled standalone page
     *  instead of layouts.app's internal dashboard chrome — same pattern as
     *  UserManagementController::hubIndex(). See RETURN_ROUTES/
     *  redirectToCaller() for how the mutating actions below know which of
     *  the two pages to send the browser back to. */
    public function hubIndex()
    {
        return view('hub-product-management', $this->buildViewData());
    }

    private function buildViewData(): array
    {
        $teamsConfig = Teams::config();
        // One flat list, no team grouping (explicit request, 2026-09-06: "the
        // product management should be one table only for products") — every
        // TSA now handles every product (see ExpandProductRosterToAllTsas),
        // so a Team Closing/Team Opening split here no longer reflects who
        // actually works a product. `team` stays a required field on each
        // product underneath (still the Add/Edit form's own select below) —
        // every report (Leads Report, TSA Performance, Analytics, Charts,
        // Dashboard, Insights) still reads it exactly as before; this is a
        // display-only change scoped to this page, not a schema change.
        $products = Product::orderBy('sort_order')->get();

        $unassigned = $products->reject(fn($p) => collect($teamsConfig)->pluck('order_team')->contains($p->team));

        $trashedProducts = Product::onlyTrashed()->orderBy('display_name')->get();

        return compact('products', 'teamsConfig', 'unassigned', 'trashedProducts');
    }

    /** Which named route store()/update()/etc. above send the browser back
     *  to — same pattern as UserManagementController::RETURN_ROUTES /
     *  redirectToCaller(). */
    private const RETURN_ROUTES = ['product-management', 'hub.product-management'];

    private function redirectToCaller(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = $request->input('_redirect_route');
        $target = in_array($target, self::RETURN_ROUTES, true) ? $target : 'product-management';

        return redirect()->route($target);
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);
        $nextSort = (int) (Product::max('sort_order') ?? 0) + 1;

        $product = Product::create([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $this->defaultTeam(),
            'sort_order'    => $nextSort,
        ]);

        $message = "Added \"{$data['display_name']}\".";
        ActivityLogger::log('product.created', $product, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        // team is deliberately untouched here — the modal no longer has a
        // field for it, so leave whatever's already saved as-is. Still
        // correctable via the bulk "Move" action on the list page if a
        // specific product's report attribution ever needs fixing.
        $product->update([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
        ]);

        $message = "Updated \"{$data['display_name']}\".";
        ActivityLogger::log('product.updated', $product, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function destroy(Request $request, Product $product)
    {
        $name = $product->display_name;
        $product->delete();

        $message = "Removed \"{$name}\".";
        ActivityLogger::log('product.deleted', $product, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    // Plain {id} param (not {product}) is deliberate — implicit route-model-binding
    // excludes soft-deleted rows by default, so a {product}-typed param would 404
    // on exactly the trashed records this route needs to find. Resolved manually.
    public function restore(Request $request, int $id)
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        $message = "Restored \"{$product->display_name}\".";
        ActivityLogger::log('product.restored', $product, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    /**
     * Permanently deletes an already-removed product — explicit request,
     * 2026-08-26: a "delete forever" action distinct from destroy() above,
     * which only ever soft-deletes. Plain {id} (not {product}), same
     * reasoning as restore() — implicit binding excludes trashed rows.
     * product_tsa/round_robin_states rows cascade-delete with it (checked
     * live in the migrations); leads.product_id already nulls out rather
     * than erroring, so a lead that once matched this product keeps
     * existing, just with no product link anymore.
     */
    public function forceDelete(Request $request, int $id)
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $name = $product->display_name;
        $product->forceDelete();

        $message = "Permanently deleted \"{$name}\".";
        ActivityLogger::log('product.force_deleted', null, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function toggleHidden(Request $request, Product $product)
    {
        $product->is_hidden = !$product->is_hidden;
        $product->save();

        $verb    = $product->is_hidden ? 'Hidden' : 'Unhidden';
        $message = "{$verb} \"{$product->display_name}\".";
        ActivityLogger::log($product->is_hidden ? 'product.hidden' : 'product.unhidden', $product, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function bulk(Request $request)
    {
        $teamsConfig = Teams::config();
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

        return $this->redirectToCaller($request)->with('success', $message);
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
        return $request->validate([
            'display_name'  => 'required|string|max:150',
            'match_keyword' => 'nullable|string|max:500',
        ]);
    }

    /** Every product still needs SOME `team` value under the hood (the
     *  column is non-nullable, and every report — Leads Report, TSA
     *  Performance, Analytics, Charts, Dashboard, Insights — still reads
     *  it), but the Add/Edit modal no longer asks for one (explicit
     *  request, 2026-09-06: every TSA now handles every product, so
     *  picking a team when adding a product no longer means anything to
     *  the person filling out the form). New products default to the
     *  first configured team; Product Management's own bulk "Move" action
     *  is still there if a specific product's team ever needs correcting
     *  for report purposes. */
    private function defaultTeam(): string
    {
        return collect(Teams::config())->first()['order_team'];
    }
}
