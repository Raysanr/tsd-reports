<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    public function index()
    {
        $apiKey   = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $apiSaved = !empty($apiKey);

        $shopId   = Setting::get('shop_id', '');
        $shopName = Setting::get('shop_name', '');

        $syncInterval = Setting::get('sync_interval', 1);
        $lastSynced   = Setting::get('last_synced');

        return view('settings', compact('apiKey', 'apiSaved', 'shopId', 'shopName', 'syncInterval', 'lastSynced'));
    }

    /** AJAX — streams 2 KB from /shops, returns shop id + name as JSON. */
    public function detect(Request $request): JsonResponse
    {
        $data   = $request->validate(['api_key' => 'required|string|min:8']);
        $result = $this->detectShop($data['api_key']);
        return response()->json($result);
    }

    public function save(Request $request)
    {
        $request->validate([
            'api_key'       => 'required|string|min:8',
            'shop_id'       => 'required|string',
            'shop_name'     => 'nullable|string',
            'sync_interval' => 'nullable|integer|min:1|max:60',
        ]);

        // Settings live in the DB only — do NOT write to .env here. Rewriting .env
        // makes the Vite dev server restart mid-redirect, which serves the settings
        // page with no CSS/JS (the "giant unstyled logo" breakage after every save).
        Setting::set('pancake_api_key', $request->input('api_key'));
        Setting::set('shop_id',         $request->input('shop_id'));
        Setting::set('shop_name',       $request->input('shop_name', $request->input('shop_id')));
        Setting::set('sync_interval',   $request->input('sync_interval', 1));

        $shopName = $request->input('shop_name', $request->input('shop_id'));
        return redirect()->route('settings')->with('success', "Connected to \"{$shopName}\" — settings saved.");
    }

    public function saveShifts(Request $request)
    {
        foreach ($request->input('shifts', []) as $key => $data) {
            TsaShift::where('tsa_key', $key)->update([
                'display_name' => $data['display_name'] ?? null,
                'shift_start'  => $data['shift_start']  ?: null,
                'shift_end'    => $data['shift_end']    ?: null,
            ]);
        }

        return redirect()->route('tsa-management')->with('success', 'Shift schedules saved.');
    }

    public function clear()
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');
        Setting::set('shop_name', '');

        return redirect()->route('settings')->with('success', 'Disconnected.');
    }

    /**
     * Verifies an API key against Pancake and returns the shop it belongs to.
     * GET /shops response shape (confirmed against Pancake's published OpenAPI
     * spec): {"shops": [{"id": <int>, "name": <string>, ...}]} — NOT {"data": [...]}.
     */
    private function detectShop(string $apiKey): array
    {
        try {
            $response = Http::timeout(5)->get('https://pos.pages.fm/api/v1/shops', [
                'api_key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Invalid API key or connection failed.'];
            }

            $body  = $response->json();
            $shops = $body['shops'] ?? [];

            if (($body['success'] ?? true) === false || empty($shops)) {
                return ['success' => false, 'message' => $body['message'] ?? 'No shops found for this API key.'];
            }

            $first = $shops[0];

            return [
                'success' => true,
                'shops'   => [[
                    'id'   => (string) ($first['id'] ?? ''),
                    'name' => $first['name'] ?? (string) ($first['id'] ?? ''),
                ]],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed. Check your API key.'];
        }
    }
}
