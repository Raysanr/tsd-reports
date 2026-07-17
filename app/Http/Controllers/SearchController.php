<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private const MAX_RESULTS_PER_TYPE = 5;

    /**
     * Powers the topbar search box. Only searches TSA agents and Products —
     * there's no order-detail page anywhere in this app to link a raw order
     * result to, so orders are deliberately out of scope here.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['tsas' => [], 'products' => []]);
        }

        $teamSlugsByOrderTeam = collect(config('teams', []))
            ->mapWithKeys(fn ($cfg, $slug) => [$cfg['order_team'] => $slug]);

        $tsas = TsaShift::where('display_name', 'like', "%{$query}%")
            ->orWhere('tsa_key', 'like', "%{$query}%")
            ->orderBy('display_name')
            ->limit(self::MAX_RESULTS_PER_TYPE)
            ->get()
            ->map(function (TsaShift $shift) use ($teamSlugsByOrderTeam) {
                $teamSlug = $teamSlugsByOrderTeam->get($shift->team);
                if ($teamSlug === null) return null;

                return [
                    'label' => $shift->display_name,
                    'url'   => route('tsa-performance.individual', ['team' => $teamSlug, 'tsaKey' => $shift->tsa_key]),
                ];
            })
            ->filter()
            ->values();

        $products = Product::where('display_name', 'like', "%{$query}%")
            ->where('is_hidden', false)
            ->orderBy('display_name')
            ->limit(self::MAX_RESULTS_PER_TYPE)
            ->get()
            ->map(function (Product $product) use ($teamSlugsByOrderTeam) {
                $teamSlug = $teamSlugsByOrderTeam->get($product->team);
                if ($teamSlug === null) return null;

                return [
                    'label' => $product->display_name,
                    'url'   => route('tsa-performance', ['team' => $teamSlug, 'product' => $product->display_name]),
                ];
            })
            ->filter()
            ->values();

        return response()->json(['tsas' => $tsas, 'products' => $products]);
    }
}
