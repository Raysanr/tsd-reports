<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

    /** Streams only the first 2 KB of the /shops response to extract shop id + name. */
    private function detectShop(string $apiKey): array
    {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->request('GET', 'https://pos.pages.fm/api/v1/shops', [
                'query'  => ['api_key' => $apiKey],
                'stream' => true,
            ]);

            if ($response->getStatusCode() !== 200) {
                return ['success' => false, 'message' => 'Invalid API key or connection failed.'];
            }

            $chunk = '';
            $body  = $response->getBody();
            while (!$body->eof() && strlen($chunk) < 2048) {
                $chunk .= $body->read(512);
            }
            $body->close();

            if (str_contains($chunk, '"success":false') || str_contains($chunk, '"success": false')) {
                preg_match('/"message"\s*:\s*"([^"]+)"/', $chunk, $msg);
                return ['success' => false, 'message' => $msg[1] ?? 'Invalid API key.'];
            }

            preg_match('/"id"\s*:\s*(\d+)/', $chunk, $idMatch);
            preg_match('/"name"\s*:\s*"([^"]+)"/', $chunk, $nameMatch);

            $shopId   = $idMatch[1]   ?? '';
            $shopName = $nameMatch[1] ?? '';

            if (empty($shopId)) {
                return ['success' => false, 'message' => 'No shops found for this API key.'];
            }

            return [
                'success' => true,
                'shops'   => [[
                    'id'   => $shopId,
                    'name' => $shopName ?: $shopId,
                ]],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed. Check your API key.'];
        }
    }
}
