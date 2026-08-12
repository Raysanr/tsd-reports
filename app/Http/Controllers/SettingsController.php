<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\ActivityLogger;
use App\Support\SyncHealth;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /** Which named route the mutating actions below send the browser back
     *  to — see redirectToCaller()'s own doc comment for why this exists. */
    private const RETURN_ROUTES = ['settings', 'calls.settings'];

    public function index()
    {
        // Explicit request (2026-08-13): this same page is now reachable
        // both from TSD Reports' own Config section (route 'settings') and
        // from Call Tracker's own sidebar (route 'calls.settings', added
        // alongside this) — one shared form/data (see redirectToCaller()),
        // but rendered inside whichever area's own layout/branding the
        // visitor actually came from, so clicking it from Call Tracker
        // doesn't look like leaving to a different app.
        $layout = request()->routeIs('calls.*') ? 'layouts.calls' : 'layouts.app';

        $apiKey       = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
        $apiKeyMasked = self::mask($apiKey);
        $apiSaved     = !empty($apiKey);

        $shopId   = Setting::get('shop_id', '');
        $shopName = Setting::get('shop_name', '');

        $syncInterval = Setting::get('sync_interval', 1);
        $lastSynced   = Setting::get('last_synced');

        $driveClientId           = Setting::get('drive_client_id', '');
        $driveClientSecret       = Setting::get('drive_client_secret', '');
        $driveClientSecretMasked = self::mask($driveClientSecret);
        $driveRefreshToken       = Setting::get('drive_refresh_token', '');
        $driveRefreshTokenMasked = self::mask($driveRefreshToken);
        $driveFolderShNaturals   = Setting::get('drive_folder_sh_naturals', '');
        $driveFolderEyecare      = Setting::get('drive_folder_eyecare', '');
        $driveConnected          = !empty($driveRefreshToken);

        $driveSyncLastRun     = Setting::get('drive_sync_last_run');
        $driveSyncLastStatus  = Setting::get('drive_sync_last_status');
        $driveSyncLastMessage = Setting::get('drive_sync_last_message');

        // Ported from call-tracker (merged into one app 2026-08-12) — Call
        // Tracker's own two extra Settings fields, folded onto this page.
        $overdueThresholdHours = LeadController::overdueThresholdHours();
        $accessToken           = Setting::get('pancake_access_token', '');
        $accessTokenMasked     = self::mask($accessToken);
        $accessTokenExpiresAt  = self::decodeJwtExpiry($accessToken);

        return view('settings', compact(
            'layout',
            'apiKeyMasked', 'apiSaved', 'shopId', 'shopName', 'syncInterval', 'lastSynced',
            'driveClientId', 'driveClientSecretMasked', 'driveRefreshTokenMasked',
            'driveFolderShNaturals', 'driveFolderEyecare', 'driveConnected',
            'driveSyncLastRun', 'driveSyncLastStatus', 'driveSyncLastMessage',
            'overdueThresholdHours', 'accessTokenMasked', 'accessTokenExpiresAt'
        ));
    }

    /**
     * Never send a saved secret's real value back to the browser just to
     * render the page — the Pancake API key and Google Drive client secret/
     * refresh token used to be echoed in full into type="password" inputs
     * (and a hidden field, for the API key), which only masks them VISUALLY;
     * the actual plaintext value still sits in the page's raw HTML, readable
     * via View Source/DevTools by anyone with access to that response.
     * Last-4-plus-dots (same convention as Stripe/GitHub token displays) lets
     * an admin recognize "yes, this is the key I saved" without exposing it.
     * save()/saveDrive() below treat a blank resubmission of a masked field
     * as "leave the existing value alone", not as "clear it".
     */
    private static function mask(string $value): string
    {
        if ($value === '') return '';

        return str_repeat('•', 12) . substr($value, -4);
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
        // The API key field only ever shows a masked placeholder now (see
        // mask() above) — formApiKey (the actual submitted field) stays empty
        // unless the admin explicitly typed a new key and it passed "Detect
        // Shop". An empty submission here means "didn't touch it", not
        // "clear it" — same page, just save whatever else changed (currently
        // only sync_interval) and leave the already-verified key/shop alone.
        $existingApiKey  = Setting::get('pancake_api_key', '');
        $submittedApiKey = trim((string) $request->input('api_key', ''));
        $keyUnchanged    = $submittedApiKey === '' && $existingApiKey !== '';

        // Ported from call-tracker (merged into one app 2026-08-12).
        // Nullable, not required: this field lives on the SAME form as the
        // pre-existing Pancake connect/sync-interval save, and every
        // existing caller of this action (including tests written before
        // this field existed) doesn't send it — an unset submission leaves
        // the previously-saved value alone rather than erroring.
        $thresholdData = $request->validate([
            'overdue_threshold_hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);
        if (array_key_exists('overdue_threshold_hours', $thresholdData) && $thresholdData['overdue_threshold_hours'] !== null) {
            Setting::set('overdue_threshold_hours', $thresholdData['overdue_threshold_hours']);
        }

        if ($keyUnchanged) {
            Setting::set('sync_interval', $request->input('sync_interval', 1));
            ActivityLogger::log('settings.sync_interval_updated', null, 'Sync interval updated.');

            return $this->redirectToCaller($request)->with('success', 'Settings saved.');
        }

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

        return $this->redirectToCaller($request)->with('success', $message);
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

        ActivityLogger::log('settings.shifts_saved', null, 'Shift schedules saved.');

        return redirect()->route('tsa-management')->with('success', 'Shift schedules saved.');
    }

    public function clear(Request $request)
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');
        Setting::set('shop_name', '');

        $message = 'Disconnected.';
        ActivityLogger::log('settings.pancake_disconnected', null, $message);

        return $this->redirectToCaller($request)->with('success', $message);
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
            'drive_client_secret'     => 'nullable|string',
            'drive_refresh_token'     => 'nullable|string',
            'drive_folder_sh_naturals'=> 'required|string',
            'drive_folder_eyecare'    => 'required|string',
        ]);

        // Same masked-field convention as the Pancake API key above (see
        // SettingsController::mask()) — Client Secret/Refresh Token only ever
        // show a masked placeholder, never the real saved value, so a blank
        // resubmission means "leave it alone", not "clear it". Only a
        // genuinely-typed new value overrides what's already stored.
        $clientSecret = trim((string) $request->input('drive_client_secret', ''));
        $clientSecret = $clientSecret !== '' ? $clientSecret : Setting::get('drive_client_secret', '');
        $refreshToken = trim((string) $request->input('drive_refresh_token', ''));
        $refreshToken = $refreshToken !== '' ? $refreshToken : Setting::get('drive_refresh_token', '');

        if ($clientSecret === '' || $refreshToken === '') {
            return back()
                ->withErrors(['drive_refresh_token' => 'Client Secret and Refresh Token are required.'])
                ->withInput();
        }

        if (!$this->verifyDriveToken(
            $request->input('drive_client_id'),
            $clientSecret,
            $refreshToken,
        )) {
            return back()
                ->withErrors(['drive_refresh_token' => 'Could not get an access token from Google with these credentials — double-check them and try again.'])
                ->withInput();
        }

        Setting::set('drive_client_id',         $request->input('drive_client_id'));
        Setting::set('drive_client_secret',     $clientSecret);
        Setting::set('drive_refresh_token',     $refreshToken);
        Setting::set('drive_folder_sh_naturals', $request->input('drive_folder_sh_naturals'));
        Setting::set('drive_folder_eyecare',     $request->input('drive_folder_eyecare'));

        $message = 'Google Drive credentials saved and verified.';
        ActivityLogger::log('settings.drive_connected', null, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function clearDrive(Request $request)
    {
        foreach (['drive_client_id', 'drive_client_secret', 'drive_refresh_token', 'drive_folder_sh_naturals', 'drive_folder_eyecare'] as $key) {
            Setting::set($key, '');
        }

        $message = 'Google Drive disconnected.';
        ActivityLogger::log('settings.drive_disconnected', null, $message);

        return $this->redirectToCaller($request)->with('success', $message);
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
    public function syncDriveNow(Request $request)
    {
        if (empty(Setting::get('drive_refresh_token'))) {
            return $this->redirectToCaller($request)->withErrors(['drive_refresh_token' => 'Save Google Drive credentials before running a manual sync.']);
        }

        // The command itself also guards against this (see SyncCallRecordings::
        // handle()) so a scheduled run starting just after this check still can't
        // overlap — this check just avoids spawning a doomed-to-skip process and
        // gives the user an immediate, honest message instead of a silent no-op.
        if (Setting::get('drive_sync_running') === '1') {
            return $this->redirectToCaller($request)->withErrors(['drive_refresh_token' => 'A sync is already running — wait for it to finish before starting another.']);
        }

        // Explicit date, not just "sync today": confirmed in production — a
        // TSA's phone can upload a recording to Drive AFTER the last scheduled
        // run for that day already happened, and since every run only ever
        // looked at "today", that recording's hour was stuck showing the flat
        // 3-min/call AHT estimate forever, with no way to go back and pick it
        // up. Defaults to today so the common "just check right now" case
        // still needs no extra input.
        $data = $request->validate([
            'date' => 'nullable|date|before_or_equal:today',
        ]);
        $date = $data['date'] ?? now('Asia/Manila')->toDateString();

        $php     = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/drive-sync-manual.log'));
        $dateArg = escapeshellarg($date);
        exec("{$php} {$artisan} calls:sync-recordings --date={$dateArg} >> {$logFile} 2>&1 &");

        ActivityLogger::log('settings.drive_sync_now', null, "Manually triggered Google Drive call-recording sync for {$date}.");

        return $this->redirectToCaller($request)->with('success', "Google Drive sync for {$date} started in the background — refresh this page in a minute or two to see the result.");
    }

    /**
     * Ported from call-tracker (merged into one app 2026-08-12).
     *
     * A personal Pancake login session token (JWT — name/session_id/fb_id
     * baked into the payload), distinct from the pancake_api_key above,
     * which is a stable, scoped integration key for the public orders API.
     * This one carries whoever pasted it's own identity and expires — used
     * for whatever Pancake features aren't reachable through the public API
     * (e.g. conversation messages, see PancakeConversationApi). Never
     * verified server-side on save (no known safe "check this session
     * token" endpoint), just stored — same blank-means-unchanged masking
     * convention as every other secret here.
     */
    public function saveAccessToken(Request $request)
    {
        $data = $request->validate(['pancake_access_token' => ['required', 'string']]);

        Setting::set('pancake_access_token', $data['pancake_access_token']);
        ActivityLogger::log('settings.pancake_access_token_saved', null, 'Pancake access token saved.');

        return $this->redirectToCaller($request)->with('success', 'Access token saved.');
    }

    public function clearAccessToken(Request $request)
    {
        Setting::set('pancake_access_token', '');
        ActivityLogger::log('settings.pancake_access_token_cleared', null, 'Pancake access token cleared.');

        return $this->redirectToCaller($request)->with('success', 'Access token cleared.');
    }

    /** Same reasoning as UserManagementController's own redirectToCaller() —
     *  this page is reachable both from TSD Reports' own Config section and
     *  from Call Tracker's sidebar (2026-08-13), both posting to the SAME
     *  actions above, and each needs to land back on whichever one it came
     *  from rather than always jumping to TSD Reports' own view. An explicit
     *  hidden `_redirect_route` field (set per-form in settings.blade.php),
     *  allowlisted against RETURN_ROUTES — never passed straight to route(). */
    private function redirectToCaller(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = $request->input('_redirect_route');
        $target = in_array($target, self::RETURN_ROUTES, true) ? $target : 'settings';

        return redirect()->route($target);
    }

    /** Reads the 'exp' claim straight out of the JWT payload for display —
     *  no signature verification, this is Pancake's job when the token is
     *  actually used; we're only decoding it to warn an admin before it
     *  silently expires. */
    private static function decodeJwtExpiry(string $token): ?Carbon
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!isset($payload['exp'])) return null;

        return Carbon::createFromTimestamp($payload['exp']);
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
            Log::error('drive:verifyToken failed', ['message' => SyncHealth::redactSecrets($e->getMessage())]);
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
            Log::error('pancake:detectShop failed', ['message' => SyncHealth::redactSecrets($e->getMessage())]);
            return ['success' => false, 'message' => 'Connection failed. Check your API key.'];
        }
    }
}
