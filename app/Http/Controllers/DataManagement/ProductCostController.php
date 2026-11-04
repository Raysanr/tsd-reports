<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ProductCost;
use App\Models\Setting;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product Costs (explicit request, 2026-11-04): the Variation id -> Product
 * Costs list from the data source sheet's LEGENDS tab. A reference list for
 * now; no report reads from it yet.
 */
class ProductCostController extends Controller
{
    private const LOCK_KEY = 'product_costs_locked';

    public function index()
    {
        return view('data.product-costs', [
            'rows' => ProductCost::orderBy('id')->get(),
            'locked' => $this->isLocked(),
        ]);
    }

    public function store(Request $request)
    {
        $this->abortIfLocked();

        $row = ProductCost::create($request->validate([
            'variation' => $this->variationRules(),
            'cost' => $this->costRules(),
        ]));
        ActivityLogger::log('product_costs.added', $row, "Added \"{$row->variation}\" at " . $this->money($row->cost) . '.');

        return redirect()->route('data.product-costs');
    }

    public function update(Request $request, ProductCost $productCost)
    {
        $this->abortIfLocked();

        $data = $request->validate([
            'variation' => ['sometimes', ...$this->variationRules($productCost)],
            'cost' => ['sometimes', ...$this->costRules()],
        ]);

        $old = $productCost->only(['variation', 'cost']);
        $productCost->update($data);

        if (array_key_exists('variation', $data) && $old['variation'] !== $productCost->variation) {
            ActivityLogger::logFieldUpdate('product_costs.field_updated', $productCost, 'variation', $old['variation'], $productCost->variation);
        }
        if (array_key_exists('cost', $data) && $old['cost'] != $productCost->cost) {
            ActivityLogger::logFieldUpdate('product_costs.field_updated', $productCost, 'cost', $old['cost'], $productCost->cost, $productCost->variation, fn ($v) => $this->money($v));
        }

        return response()->json(['success' => true]);
    }

    public function destroy(ProductCost $productCost)
    {
        $this->abortIfLocked();

        $productCost->delete();
        ActivityLogger::log('product_costs.removed', $productCost, "Removed \"{$productCost->variation}\" (" . $this->money($productCost->cost) . ').');

        return response()->json(['success' => true]);
    }

    public function toggleLock(Request $request)
    {
        $locked = $request->boolean('locked');
        Setting::set(self::LOCK_KEY, $locked ? '1' : '');
        ActivityLogger::log($locked ? 'product_costs.table_locked' : 'product_costs.table_unlocked', null, ($locked ? 'Locked' : 'Unlocked') . ' the Product Costs table.');

        return response()->json(['success' => true, 'locked' => $locked]);
    }

    private function variationRules(?ProductCost $ignore = null): array
    {
        return ['required', 'string', 'max:255', Rule::unique('product_costs', 'variation')->ignore($ignore)];
    }

    private function costRules(): array
    {
        return ['required', 'numeric', 'min:0', 'max:99999999.99'];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    private function isLocked(): bool
    {
        return (bool) Setting::get(self::LOCK_KEY);
    }

    private function abortIfLocked(): void
    {
        abort_if($this->isLocked(), 423, 'The Product Costs table is locked.');
    }
}
