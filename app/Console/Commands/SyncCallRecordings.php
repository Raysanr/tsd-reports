<?php

namespace App\Console\Commands;

use App\Models\CallRecordingHour;
use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sums real per-hour call-duration totals from the Google Drive folders each
 * TSA's device auto-uploads recordings into, replacing the "3 minutes per
 * answered call" flat OPT assumption on the individual TSA page with real
 * data wherever it's available (see TsaPerformanceController::showTsa()).
 *
 * The real folder tree (TSD 2026 RECORDING > TEAM <X> > <MONTH> CALL
 * RECORDINGS > <TSA NAME> > ...) nests inconsistently below the TSA-name
 * level — sometimes a cleanly-dated subfolder, sometimes "(FOLLOW UP)"
 * variants, sometimes files sitting directly in the TSA folder with no date
 * subfolder at all. Rather than trust any of that folder naming, every
 * recording's own filename ("<phone> <YYYY-MM-DD> <HH-MM-SS>.m4a") is the
 * single source of truth for which date/hour it belongs to — the folder
 * structure is only ever used to narrow down WHICH files to even look at.
 */
class SyncCallRecordings extends Command
{
    protected $signature = 'calls:sync-recordings {--date= : Date to sync (Y-m-d, Philippine time), defaults to today}';
    protected $description = 'Sum real call-recording durations per TSA per hour from Google Drive';

    /** Setting key holding each team's root "TEAM <X>" folder id, keyed by the
     *  literal order_team string stored on tsa_shifts.team. */
    private const FOLDER_SETTING_KEYS = [
        'SH Naturals'  => 'drive_folder_sh_naturals',
        'Eyecare Team' => 'drive_folder_eyecare',
    ];

    /** Recursion guard for collectFilesRecursively() — the messiest real TSA
     *  folder seen while mapping this out was 3 levels deep (TSA > date
     *  variant > files); this leaves headroom without risking a runaway walk
     *  if a folder is ever nested unexpectedly deeply. */
    private const MAX_DEPTH = 4;

    public function handle(): int
    {
        // Recorded on every invocation regardless of outcome — surfaced on the
        // Settings page (see SettingsController::index()) so a silent production
        // failure is actually visible instead of just "still no data" with no clue
        // why. This command previously had zero observability, unlike the Pancake
        // sync's last_synced/sync_stale tracking (SyncHealth).
        Setting::set('drive_sync_last_run', now()->toIso8601String());

        try {
            return $this->sync();
        } catch (\Throwable $e) {
            Setting::set('drive_sync_last_status', 'error');
            Setting::set('drive_sync_last_message', $e->getMessage());
            Log::error('calls:sync-recordings failed', ['message' => $e->getMessage()]);
            $this->error('Unexpected error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function sync(): int
    {
        $clientId     = Setting::get('drive_client_id');
        $clientSecret = Setting::get('drive_client_secret');
        $refreshToken = Setting::get('drive_refresh_token');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            $this->recordFailure('Google Drive credentials are not configured (Settings).');
            return self::FAILURE;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');
        $dateString = $date->toDateString();
        $monthLabel = strtoupper($date->format('F')); // e.g. "JULY"

        $token = $this->getAccessToken($clientId, $clientSecret, $refreshToken);
        if (!$token) {
            $this->recordFailure('Failed to refresh Google Drive access token — check the stored credentials.');
            return self::FAILURE;
        }

        // tsa_key => [hour => ['seconds' => float, 'count' => int]]
        $totals = [];

        foreach (self::FOLDER_SETTING_KEYS as $orderTeam => $settingKey) {
            $rootId = Setting::get($settingKey);
            if (!$rootId) {
                $this->warn("No Drive folder configured for {$orderTeam} ({$settingKey}) — skipped.");
                continue;
            }

            $shifts = TsaShift::where('team', $orderTeam)->get();
            if ($shifts->isEmpty()) continue;

            $monthFolders = $this->listChildren($token, $rootId);
            $monthFolder  = collect($monthFolders)->first(
                fn($f) => str_contains(strtoupper($f['name']), $monthLabel)
                    && str_contains(strtoupper($f['name']), 'CALL RECORDING')
            );
            if (!$monthFolder) {
                $this->warn("No \"{$monthLabel} CALL RECORDINGS\" folder found for {$orderTeam} — skipped.");
                continue;
            }

            $tsaFolders = $this->listChildren($token, $monthFolder['id']);

            foreach ($shifts as $shift) {
                $tsaFolder = collect($tsaFolders)->first(
                    fn($f) => $this->namesMatch($f['name'], $shift->display_name)
                        || $this->namesMatch($f['name'], $shift->tsa_key)
                );
                if (!$tsaFolder) continue; // no recordings folder for this TSA this month

                $files = $this->collectFilesRecursively($token, $tsaFolder['id'], 0);

                foreach ($files as $file) {
                    $parsed = $this->parseFilename($file['name']);
                    if (!$parsed || $parsed['date'] !== $dateString) continue;

                    $bytes = $this->downloadFile($token, $file['id']);
                    if ($bytes === null) continue;

                    $seconds = $this->m4aDurationSeconds($bytes);
                    if ($seconds === null) continue;

                    $hour = $parsed['hour'];
                    $totals[$shift->tsa_key][$hour]['seconds'] = ($totals[$shift->tsa_key][$hour]['seconds'] ?? 0) + $seconds;
                    $totals[$shift->tsa_key][$hour]['count']   = ($totals[$shift->tsa_key][$hour]['count']   ?? 0) + 1;
                }
            }
        }

        $totalRecordings = 0;
        foreach ($totals as $tsaKey => $hours) {
            foreach ($hours as $hour => $data) {
                CallRecordingHour::updateOrCreate(
                    ['tsa_key' => $tsaKey, 'date' => $dateString, 'hour' => $hour],
                    [
                        'total_seconds' => (int) round($data['seconds']),
                        'call_count'    => $data['count'],
                        'synced_at'     => now(),
                    ]
                );
            }
            $tsaCount = array_sum(array_column($hours, 'count'));
            $totalRecordings += $tsaCount;
            $this->info("{$tsaKey}: {$tsaCount} recording(s) synced for {$dateString}.");
        }

        Setting::set('drive_sync_last_status', 'success');
        Setting::set('drive_sync_last_message', "Synced {$totalRecordings} recording(s) across " . count($totals) . " TSA(s) for {$dateString}.");

        return self::SUCCESS;
    }

    /** Records a hard-stop failure to Settings (surfaced on the Settings page)
     *  before returning — every failure path this command has must go through
     *  here so "no data yet" is always explainable without guessing. */
    private function recordFailure(string $message): void
    {
        Setting::set('drive_sync_last_status', 'failed');
        Setting::set('drive_sync_last_message', $message);
        $this->error($message);
    }

    private function namesMatch(string $folderName, string $tsaName): bool
    {
        return strtoupper(trim($folderName)) === strtoupper(trim($tsaName));
    }

    /** "<phone> <YYYY-MM-DD> <HH-MM-SS>.m4a" — the phone number's own format
     *  varies (with/without +63, spacing), so this only anchors on the
     *  date+time portion, which is consistently formatted across every
     *  recording seen while mapping this folder tree out. */
    private function parseFilename(string $name): ?array
    {
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{2})-\d{2}-\d{2}/', $name, $m)) {
            return null;
        }
        return ['date' => $m[1], 'hour' => (int) $m[2]];
    }

    private function collectFilesRecursively(string $token, string $folderId, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) return [];

        $files = [];
        foreach ($this->listChildren($token, $folderId) as $child) {
            if ($child['mimeType'] === 'application/vnd.google-apps.folder') {
                $files = array_merge($files, $this->collectFilesRecursively($token, $child['id'], $depth + 1));
            } elseif (str_ends_with(strtolower($child['name']), '.m4a')) {
                $files[] = $child;
            }
        }
        return $files;
    }

    private function getAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?string
    {
        $res = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        return $res->successful() ? $res->json('access_token') : null;
    }

    private function listChildren(string $token, string $folderId): array
    {
        $res = Http::withToken($token)->timeout(20)->get('https://www.googleapis.com/drive/v3/files', [
            'q'        => "'{$folderId}' in parents and trashed = false",
            'fields'   => 'files(id,name,mimeType)',
            'pageSize' => 200,
        ]);
        return $res->successful() ? $res->json('files', []) : [];
    }

    private function downloadFile(string $token, string $fileId): ?string
    {
        $res = Http::withToken($token)->timeout(30)
            ->get("https://www.googleapis.com/drive/v3/files/{$fileId}", ['alt' => 'media']);
        return $res->successful() ? $res->body() : null;
    }

    /** Minimal MP4/M4A atom parser — walks top-level boxes to find moov > mvhd,
     *  reads timescale + duration to compute the real audio length in
     *  seconds. No library dependency: mvhd's layout is a stable, decades-old
     *  ISO/IEC 14496-12 spec, and every recording here is a plain, unfragmented
     *  m4a — no need for a general-purpose media parser to read one field. */
    private function m4aDurationSeconds(string $bytes): ?float
    {
        $len = strlen($bytes);
        $pos = 0;
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $size = unpack('J', substr($bytes, $pos + 8, 8))[1];
                $headerSize = 16;
            }
            if ($size === 0) $size = $len - $pos;

            if ($type === 'moov') {
                return $this->m4aParseMoov(substr($bytes, $pos + $headerSize, $size - $headerSize));
            }
            if ($size <= 0) break;
            $pos += $size;
        }
        return null;
    }

    private function m4aParseMoov(string $moov): ?float
    {
        $len = strlen($moov);
        $pos = 0;
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($moov, $pos, 4))[1];
            $type = substr($moov, $pos + 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $size = unpack('J', substr($moov, $pos + 8, 8))[1];
                $headerSize = 16;
            }
            if ($size === 0) $size = $len - $pos;

            if ($type === 'mvhd') {
                $body    = substr($moov, $pos + $headerSize, $size - $headerSize);
                $version = ord($body[0]);
                if ($version === 1) {
                    $timescale = unpack('N', substr($body, 20, 4))[1];
                    $duration  = unpack('J', substr($body, 24, 8))[1];
                } else {
                    $timescale = unpack('N', substr($body, 12, 4))[1];
                    $duration  = unpack('N', substr($body, 16, 4))[1];
                }
                return $timescale > 0 ? $duration / $timescale : null;
            }
            if ($size <= 0) break;
            $pos += $size;
        }
        return null;
    }
}
