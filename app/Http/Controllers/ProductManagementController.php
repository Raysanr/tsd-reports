<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

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

        return view('product-management', compact('teamGroups', 'teamsConfig', 'unassigned'));
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);
        $nextSort = (int) (Product::max('sort_order') ?? 0) + 1;

        Product::create([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
            'sort_order'    => $nextSort,
        ]);

        return redirect()->route('product-management')
            ->with('success', "Added \"{$data['display_name']}\".");
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        $product->update([
            'display_name'  => $data['display_name'],
            'match_keyword' => $data['match_keyword'] ?: null,
            'team'          => $data['team'],
        ]);

        return redirect()->route('product-management')
            ->with('success', "Updated \"{$data['display_name']}\".");
    }

    public function destroy(Product $product)
    {
        $name = $product->display_name;
        $product->delete();

        return redirect()->route('product-management')
            ->with('success', "Removed \"{$name}\".");
    }

    public function toggleHidden(Product $product)
    {
        $product->is_hidden = !$product->is_hidden;
        $product->save();

        $verb = $product->is_hidden ? 'Hidden' : 'Unhidden';

        return redirect()->route('product-management')
            ->with('success', "{$verb} \"{$product->display_name}\".");
    }

    private function validateProduct(Request $request): array
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        return $request->validate([
            'display_name'  => 'required|string|max:150',
            'match_keyword' => 'nullable|string|max:150',
            'team'          => 'required|string|in:' . implode(',', $validTeams),
        ]);
    }
}
