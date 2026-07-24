<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $driveClientId         = Setting::get('drive_client_id', '');
        $driveClientSecret     = Setting::get('drive_client_secret', '');
        $driveRefreshToken     = Setting::get('drive_refresh_token', '');
        $driveFolderShNaturals = Setting::get('drive_folder_sh_naturals', '');
        $driveFolderEyecare    = Setting::get('drive_folder_eyecare', '');
        $driveConnected        = !empty($driveRefreshToken);

        $driveSyncLastRun     = Setting::get('drive_sync_last_run');
        $driveSyncLastStatus  = Setting::get('drive_sync_last_status');
        $driveSyncLastMessage = Setting::get('drive_sync_last_message');

        return view('settings', compact(
            'apiKey', 'apiSaved', 'shopId', 'shopName', 'syncInterval', 'lastSynced',
            'driveClientId', 'driveClientSecret', 'driveRefreshToken',
            'driveFolderShNaturals', 'driveFolderEyecare', 'driveConnected',
            'driveSyncLastRun', 'driveSyncLastStatus', 'driveSyncLastMessage'
        ));
    }

    /** AJAX — verifies the API key against Pancake's /shops endpoint, returns shop id + name as JSON. */
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

        // Re-verify server-side: the api_key/shop_id fields on this form are
        // hidden inputs populated by the "Detect Shop" AJAX call, but nothing
        // stops a stale page, a skipped detect step, or a direct POST from
        // submitting an unverified value here. Trusting them without a second
        // check is exactly how a placeholder ("test-key") once overwrote a
        // working key and broke every scheduled sync for ~19 hours with no
        // visible error until someone checked the sync-run history.
        $verification = $this->detectShop($request->input('api_key'));

        if (!$verification['success']) {
            return back()
                ->withErrors(['api_key' => $verification['message'] ?? 'That API key could not be verified with Pancake POS.'])
                ->withInput();
        }

        $verifiedShopId = $verification['shops'][0]['id'] ?? null;
        if ($verifiedShopId !== null && $verifiedShopId !== (string) $request->input('shop_id')) {
            return back()
                ->withErrors(['api_key' => 'This API key belongs to a different shop than the one being saved. Click "Detect Shop" again to refresh it.'])
                ->withInput();
        }

        // Settings live in the DB only — do NOT write to .env here. Rewriting .env
        // makes the Vite dev server restart mid-redirect, which serves the settings
        // page with no CSS/JS (the "giant unstyled logo" breakage after every save).
        Setting::set('pancake_api_key', $request->input('api_key'));
        Setting::set('shop_id',         $request->input('shop_id'));
        Setting::set('shop_name',       $request->input('shop_name', $request->input('shop_id')));
        Setting::set('sync_interval',   $request->input('sync_interval', 1));

        $shopName = $request->input('shop_name', $request->input('shop_id'));
        $message  = "Connected to \"{$shopName}\" — settings saved.";

        // Subject is null (Setting isn't a per-row auditable model in this app's
        // schema). Critically: only the shop name is ever logged here, never the
        // API key itself — same as the flash message this description mirrors.
        ActivityLogger::log('settings.pancake_connected', null, $message);

        return redirect()->route('settings')->with('success', $message);
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

        $message = 'Disconnected.';
        ActivityLogger::log('settings.pancake_disconnected', null, $message);

        return redirect()->route('settings')->with('success', $message);
    }

    /**
     * Feeds SyncCallRecordings (real call-duration data for TSA Performance's
     * OPT/AHT columns). Verifies the refresh token actually works before saving —
     * same reasoning as detectShop() above: an untested value here would silently
     * fail every 2 hours in the scheduled sync with nothing visible on this page.
     */
    public function saveDrive(Request $request)
    {
        $request->validate([
            'drive_client_id'         => 'required|string',
            'drive_client_secret'     => 'required|string',
            'drive_refresh_token'     => 'required|string',
            'drive_folder_sh_naturals'=> 'required|string',
            'drive_folder_eyecare'    => 'required|string',
        ]);

        if (!$this->verifyDriveToken(
            $request->input('drive_client_id'),
            $request->input('drive_client_secret'),
            $request->input('drive_refresh_token'),
        )) {
            return back()
                ->withErrors(['drive_refresh_token' => 'Could not get an access token from Google with these credentials — double-check them and try again.'])
                ->withInput();
        }

        Setting::set('drive_client_id',         $request->input('drive_client_id'));
        Setting::set('drive_client_secret',     $request->input('drive_client_secret'));
        Setting::set('drive_refresh_token',     $request->input('drive_refresh_token'));
        Setting::set('drive_folder_sh_naturals', $request->input('drive_folder_sh_naturals'));
        Setting::set('drive_folder_eyecare',     $request->input('drive_folder_eyecare'));

        $message = 'Google Drive credentials saved and verified.';
        ActivityLogger::log('settings.drive_connected', null, $message);

        return redirect()->route('settings')->with('success', $message);
    }

    public function clearDrive()
    {
        foreach (['drive_client_id', 'drive_client_secret', 'drive_refresh_token', 'drive_folder_sh_naturals', 'drive_folder_eyecare'] as $key) {
            Setting::set($key, '');
        }

        $message = 'Google Drive disconnected.';
        ActivityLogger::log('settings.drive_disconnected', null, $message);

        return redirect()->route('settings')->with('success', $message);
    }

    /**
     * Manual trigger for calls:sync-recordings, so a saved connection can be
     * confirmed working without waiting up to 2 hours for the next scheduled
     * run. Launched as a DETACHED background process (exec ... &), same as
     * CronController::run() — this container serves every request through a
     * single php artisan serve worker (no worker pool), and a full sync across
     * every TSA/team has taken several minutes end to end. Running that
     * in-process would freeze the entire app (including Render's own health
     * check) for everyone until it finished — confirmed as the exact failure
     * mode CronController's own doc comment describes avoiding for the Pancake
     * sync. This returns immediately; the "Last sync" status block on this
     * page (populated by the command itself) reflects the real outcome once
     * the background process finishes.
     */
    public function syncDriveNow()
    {
        if (empty(Setting::get('drive_refresh_token'))) {
            return redirect()->route('settings')->withErrors(['drive_refresh_token' => 'Save Google Drive credentials before running a manual sync.']);
        }

        $php     = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/drive-sync-manual.log'));
        exec("{$php} {$artisan} calls:sync-recordings >> {$logFile} 2>&1 &");

        return redirect()->route('settings')->with('success', 'Google Drive sync started in the background — refresh this page in a minute or two to see the result.');
    }

    private function verifyDriveToken(string $clientId, string $clientSecret, string $refreshToken): bool
    {
        try {
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);

            return $response->successful() && !empty($response->json('access_token'));
        } catch (\Throwable $e) {
            Log::error('drive:verifyToken failed', ['message' => $e->getMessage()]);
            return false;
        }
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

            $first  = $shops[0];
            $shopId = (string) ($first['id'] ?? '');

            return [
                'success' => true,
                'shops'   => [[
                    'id'   => $shopId,
                    'name' => $first['name'] ?? $shopId,
                ]],
            ];
        } catch (\Throwable $e) {
            Log::error('pancake:detectShop failed', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Connection failed. Check your API key.'];
        }
    }
}
