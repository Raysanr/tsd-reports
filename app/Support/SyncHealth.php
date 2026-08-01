<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

class SyncHealth
{
    /**
     * @return array{last_synced: ?string, sync_interval: int, sync_stale: bool}
     */
    public static function status(): array
    {
        $lastSynced      = Setting::get('last_synced');
        $syncIntervalMin = max(1, min(60, (int) Setting::get('sync_interval', 2)));

        // The scheduler re-syncs every $syncIntervalMin minutes. If nothing landed
        // for 3x that interval, the cron has likely stopped firing (server down,
        // schedule:run not wired up, API key revoked, etc.).
        $syncStale = !$lastSynced
            || Carbon::parse($lastSynced)->diffInMinutes(now()) > ($syncIntervalMin * 3);

        return [
            'last_synced'   => $lastSynced,
            'sync_interval' => $syncIntervalMin,
            'sync_stale'    => $syncStale,
        ];
    }

    /** Strips known secret query-string values from an error message before it's
     *  persisted or shown anywhere. SyncRun.error_message and
     *  drive_sync_last_message can both contain a raw request URI (SyncTodayOrders
     *  builds the Pancake request with api_key as a query-string param, and Guzzle
     *  connection-exception messages — timeouts, DNS blips — include the full
     *  request URI; SyncCallRecordings' Google OAuth calls are body-based today,
     *  not query-string, but are covered here too in case that ever changes).
     *  Applied both at WRITE time (SyncTodayOrders::recordRun(), so the raw value
     *  never reaches the database in the first place) and at every point one of
     *  these fields is later surfaced to a browser (DashboardController::sync's
     *  JSON response, SyncHealthController::retry's flash message, Sync Health's
     *  run-history table) — defense in depth, not an either/or. Shared here
     *  (rather than duplicated per call site) so every copy of this
     *  security-relevant regex can never drift apart. */
    public static function redactSecrets(?string $message): ?string
    {
        if ($message === null) return null;

        return preg_replace(
            '/([?&](?:api_key|client_secret|refresh_token|access_token)=)[^&\s]+/i',
            '$1REDACTED',
            $message
        );
    }
}
