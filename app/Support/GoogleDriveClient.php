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

    /** Recursion guard shared with the recursive file walk below — mirrors
     *  SyncCallRecordings::MAX_DEPTH's own reasoning (the messiest real TSA
     *  folder seen while mapping this out was 3 levels deep). */
    private const MAX_WALK_DEPTH = 4;

    /**
     * TSA folders now sit under a MONTH folder under their team's root
     * (confirmed live, 2026-08-25: TSD 2026 RECORDING > TEAM SH NATURALS >
     * AUGUST > <tsa_key or display_name>) — an outer layer added on top of
     * the flat Team > TSA structure this previously assumed. Looks for a
     * folder matching $forDate's full month name (e.g. "August" — matches
     * "AUGUST" case-insensitively via namesMatch()) directly under the team
     * root first; if found, resolves the TSA inside THAT. Falls back to
     * treating the team root itself as the TSA's parent (the old flat
     * lookup) when no matching month folder exists — keeps this working
     * for a team that hasn't adopted the month layer yet, or the first few
     * days of a new month before that month's folder has been created.
     */
    public function resolveTsaFolder(string $token, TsaShift $tsa, ?\Illuminate\Support\Carbon $forDate = null): ?array
    {
        $settingKey = self::FOLDER_SETTING_KEYS[$tsa->team] ?? null;
        if (!$settingKey) return null;

        $teamRootId = Setting::get($settingKey);
        if (!$teamRootId) return null;

        $forDate ??= now('Asia/Manila');
        $monthFolder = collect($this->listChildren($token, $teamRootId))->first(
            fn ($f) => $f['mimeType'] === 'application/vnd.google-apps.folder' && $this->namesMatch($f['name'], $forDate->format('F'))
        );

        $tsaParentId = $monthFolder['id'] ?? $teamRootId;
        $tsaFolder   = collect($this->listChildren($token, $tsaParentId))->first(
            fn ($f) => $this->namesMatch($f['name'], $tsa->display_name) || $this->namesMatch($f['name'], $tsa->tsa_key)
        );

        // Month folder existed but didn't have this TSA (e.g. genuinely no
        // recordings yet this month) — still try the team root directly
        // before giving up, same fallback reasoning as above.
        if (!$tsaFolder && $monthFolder) {
            $tsaFolder = collect($this->listChildren($token, $teamRootId))->first(
                fn ($f) => $this->namesMatch($f['name'], $tsa->display_name) || $this->namesMatch($f['name'], $tsa->tsa_key)
            );
        }

        return $tsaFolder;
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
