<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Drive v3 REST calls needed by both
 * SyncCallRecordings (nightly per-hour duration totals) and
 * LeadController's "listen to this customer's recording" feature
 * (2026-08-19) — pulled out of SyncCallRecordings, which had all of this
 * duplicated inline as private methods, so the two features can't drift on
 * how a token is fetched or how a TSA's own Drive folder is found. Same
 * single shared refresh token either way (Settings:
 * drive_client_id/secret/refresh_token) — there's only ever one Drive
 * connection for the whole app, not one per TSA.
 */
class GoogleDriveClient
{
    /** Setting key holding each team's root "TEAM <X>" folder id, keyed by the
     *  literal order_team string stored on tsa_shifts.team. */
    public const FOLDER_SETTING_KEYS = [
        'SH Naturals'  => 'drive_folder_sh_naturals',
        'Eyecare Team' => 'drive_folder_eyecare',
    ];

    public function accessToken(): ?string
    {
        $clientId     = Setting::get('drive_client_id');
        $clientSecret = Setting::get('drive_client_secret');
        $refreshToken = Setting::get('drive_refresh_token');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            return null;
        }

        $res = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        return $res->successful() ? $res->json('access_token') : null;
    }

    /** Paginated — a TSA's folder is a single flat list of every recording
     *  they've ever had (no date subfolders), so it will eventually pass
     *  200 files. A single unpaginated page would silently drop everything
     *  past the 200th with no error. */
    public function listChildren(string $token, string $folderId): array
    {
        $files = [];
        $pageToken = null;

        do {
            $res = Http::withToken($token)->timeout(20)->get('https://www.googleapis.com/drive/v3/files', array_filter([
                'q'         => "'{$folderId}' in parents and trashed = false",
                'fields'    => 'nextPageToken, files(id,name,mimeType)',
                'pageSize'  => 200,
                'pageToken' => $pageToken,
            ]));
            if (!$res->successful()) break;

            $files = array_merge($files, $res->json('files', []));
            $pageToken = $res->json('nextPageToken');
        } while ($pageToken);

        return $files;
    }

    public function downloadFile(string $token, string $fileId): ?string
    {
        $res = Http::withToken($token)->timeout(30)
            ->get("https://www.googleapis.com/drive/v3/files/{$fileId}", ['alt' => 'media']);
        return $res->successful() ? $res->body() : null;
    }

    /** Forwards an optional browser Range header straight through to Drive's
     *  own media download (Drive supports HTTP Range requests same as any
     *  static file host) and returns exactly what Drive replied with, so
     *  LeadController::streamRecording() can relay a faithful 206 Partial
     *  Content — or plain 200 — response back to the <audio> element.
     *  Confirmed live, 2026-08-24: real phone-recorded M4A/MP4 files are
     *  almost never "fast-start" optimized (their moov metadata atom sits
     *  at the end of the file, after the audio data itself), so a browser
     *  can only ever find it by seeking via Range requests — without this,
     *  streamRecording()'s old plain single-shot response left the player
     *  showing working controls but stuck at 0:00/0:00 forever, even
     *  though the exact same bytes download and parse as perfectly valid
     *  M4A outside a browser. A separate method (not an optional param on
     *  downloadFile() above) so SyncCallRecordings' own call — which wants
     *  one predictable ?string return, not a Range-negotiated one — is
     *  untouched. */
    /** Wrapped in try/catch (confirmed live, 2026-08-24: a slow/unreliable
     *  path to Drive — e.g. a single-threaded `php artisan serve` dev
     *  server queuing this behind another slow request — can time out and
     *  throw ConnectionException, which previously bubbled all the way up
     *  to an unhandled 500 with the player showing nothing and no way to
     *  tell why) — returns 'successful' => false instead, same shape a
     *  clean non-2xx/206 Drive response already produces, so callers only
     *  ever need one failure branch. */
    public function downloadFileRanged(string $token, string $fileId, ?string $rangeHeader): array
    {
        $request = Http::withToken($token)->timeout(30);
        if ($rangeHeader) {
            $request = $request->withHeaders(['Range' => $rangeHeader]);
        }

        try {
            $res = $request->get("https://www.googleapis.com/drive/v3/files/{$fileId}", ['alt' => 'media']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['successful' => false, 'status' => 504, 'body' => '', 'content_range' => null, 'content_length' => null];
        }

        return [
            'successful'     => $res->successful() || $res->status() === 206,
            'status'         => $res->status(),
            'body'           => $res->body(),
            'content_range'  => $res->header('Content-Range'),
            'content_length' => $res->header('Content-Length'),
        ];
    }

    public function namesMatch(string $a, string $b): bool
    {
        return strtoupper(trim($a)) === strtoupper(trim($b));
    }

    /** Recursion guard shared with the recursive file walk below — mirrors
     *  SyncCallRecordings::MAX_DEPTH's own reasoning (the messiest real TSA
     *  folder seen while mapping this out was 3 levels deep). */
    private const MAX_WALK_DEPTH = 4;

    /** Words too generic to identify a TSA from a folder name. */
    private const IGNORED_MATCH_WORDS = ['TSA'];

    /** Exact match (ignoring case) against display_name, tsa_key or any of
     *  her tag_keywords (2026-09-08: Angel's tsa_key is "Angelica" but her
     *  folder is "ANGEL", one of her tag keywords). */
    private function exactFolderMatch(string $folderName, TsaShift $tsa): bool
    {
        foreach (array_merge([$tsa->display_name, $tsa->tsa_key], $tsa->tag_keywords_array) as $name) {
            if ($name !== null && $name !== '' && $this->namesMatch($folderName, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Word match (2026-11-12): display_name and tag keywords split on spaces,
     *  dashes and underscores; words under 3 letters and "TSA" ignored. So
     *  "Trocio LIKA" matches folder LIKA and tag "ANGEL-TSA" matches ANGEL. */
    private function wordFolderMatch(string $folderName, TsaShift $tsa): bool
    {
        foreach (array_merge([$tsa->display_name], $tsa->tag_keywords_array) as $name) {
            foreach (preg_split('/[\s\-_]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (mb_strlen($word) >= 3 && !in_array(strtoupper($word), self::IGNORED_MATCH_WORDS, true) && $this->namesMatch($folderName, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Drive layout (2026-11-12): each team's recordings folder (Settings:
     * CLOSING / OPENING Folder ID) holds one subfolder per TSA; inside it are
     * day folders (any name) holding "<phone> YYYY-MM-DD HH-MM-SS.m4a" files.
     * A TSA is only ever looked for inside her own team's folder.
     *
     * Matching: a TSA with drive_folder_name set matches only the subfolder
     * with exactly that name (ignoring case). Otherwise: an exact match on
     * display name, TSA key or tag keyword first, then a word match.
     */
    public function resolveTsaFolder(string $token, TsaShift $tsa, ?\Illuminate\Support\Carbon $forDate = null): ?array
    {
        $settingKey = self::FOLDER_SETTING_KEYS[$tsa->team] ?? null;
        $teamRootId = $settingKey ? Setting::get($settingKey) : null;
        if (!$teamRootId) {
            return null;
        }

        $folders = collect($this->listChildren($token, $teamRootId))
            ->filter(fn ($f) => ($f['mimeType'] ?? null) === 'application/vnd.google-apps.folder')
            ->values();

        $override = trim((string) $tsa->drive_folder_name);
        if ($override !== '') {
            return $folders->first(fn ($f) => $this->namesMatch($f['name'], $override));
        }

        return $folders->first(fn ($f) => $this->exactFolderMatch($f['name'], $tsa))
            ?? $folders->first(fn ($f) => $this->wordFolderMatch($f['name'], $tsa));
    }

    /** Shared recursive file walk (extracted from SyncCallRecordings, which
     *  had this as a private method before LeadController's own recording-
     *  playback lookup needed the identical logic — confirmed live,
     *  2026-08-25: a TSA's day folders sit ONE level under their own folder
     *  (e.g. "MARIEL/AUGUST 7/<recordings>"), so a flat listChildren() on
     *  the TSA folder alone finds only day-folders, never actual .m4a
     *  files — this was silently broken for recording playback specifically
     *  until this fix, since that lookup never recursed at all). Same
     *  MAX_WALK_DEPTH guard as SyncCallRecordings::MAX_DEPTH always had. */
    public function listFilesRecursively(string $token, string $folderId, int $depth = 0): array
    {
        if ($depth > self::MAX_WALK_DEPTH) return [];

        $files = [];
        foreach ($this->listChildren($token, $folderId) as $child) {
            if ($child['mimeType'] === 'application/vnd.google-apps.folder') {
                $files = array_merge($files, $this->listFilesRecursively($token, $child['id'], $depth + 1));
            } elseif (str_ends_with(strtolower($child['name']), '.m4a')) {
                $files[] = $child;
            }
        }
        return $files;
    }
}
