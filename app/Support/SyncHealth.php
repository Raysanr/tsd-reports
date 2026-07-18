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

    /** Strips any api_key query-string value from an error message before it's
     *  returned in an HTTP response. SyncRun.error_message can contain the raw
     *  request URI (SyncTodayOrders builds the Pancake request with api_key as
     *  a query-string param, and Guzzle connection-exception messages —
     *  timeouts, DNS blips — include the full request URI). Any endpoint that
     *  surfaces this field to the browser (DashboardController::sync's JSON
     *  response, SyncHealthController::retry's flash message) must run it
     *  through here first — both are reachable by users who must never see
     *  the live Pancake API key, the same key the Settings page masks from
     *  them. Shared here (rather than duplicated per controller) so the two
     *  copies of this security-relevant regex can never drift apart. */
    public static function redactSecrets(?string $message): ?string
    {
        return $message === null
            ? null
            : preg_replace('/([?&]api_key=)[^&\s]+/i', '$1REDACTED', $message);
    }
}
