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

    public function namesMatch(string $a, string $b): bool
    {
        return strtoupper(trim($a)) === strtoupper(trim($b));
    }

    /** TSA folders sit directly under their team's root (TSD CALLS >
     *  SH NATURALS|EYECARE > <tsa_key or display_name>) — resolves that one
     *  folder for a given TSA, or null if Drive isn't configured for their
     *  team or no matching folder exists yet. */
    public function resolveTsaFolder(string $token, TsaShift $tsa): ?array
    {
        $settingKey = self::FOLDER_SETTING_KEYS[$tsa->team] ?? null;
        if (!$settingKey) return null;

        $rootId = Setting::get($settingKey);
        if (!$rootId) return null;

        return collect($this->listChildren($token, $rootId))->first(
            fn ($f) => $this->namesMatch($f['name'], $tsa->display_name) || $this->namesMatch($f['name'], $tsa->tsa_key)
        );
    }
}
