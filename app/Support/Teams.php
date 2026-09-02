<?php

namespace App\Support;

use App\Models\Setting;

/**
 * config('teams') plus an editable display-name overlay — explicit request,
 * 2026-09-02: "is it possible that the team sh naturals and eyecare is
 * editable." Deliberately keeps the array KEY (e.g. 'sh-naturals') and
 * 'order_team' (the literal string synced from Pancake into orders.team/
 * tsa_shifts.team — see config/teams.php's own doc comment) fixed and NOT
 * editable — every route/session/query-string 'team' param throughout the
 * app is keyed by the slug, and order_team has to match real synced data
 * exactly. Only 'name' (the display label shown in the UI) is overridable,
 * stored per-team as a Setting row (settings.key = "team_name_{slug}") —
 * same mechanism this app already uses for every other admin-editable value
 * (see SettingsController), rather than a new table for what's currently
 * just 2 fixed teams. Team CREATION stays out of scope, same explicit
 * decision as docs/superpowers/specs/2026-07-06-product-management-design.md
 * made for Product Management's own team handling.
 */
class Teams
{
    /** Setting key for one team's overridden display name. */
    public static function nameSettingKey(string $slug): string
    {
        return "team_name_{$slug}";
    }

    /** Same shape as config('teams') (slug => ['name' => ..., 'order_team' =>
     *  ...]), with any admin-saved display-name override applied — every
     *  existing config('teams') call site can swap to this with no other
     *  change, since the returned structure is identical. */
    public static function config(): array
    {
        $teams = config('teams', []);

        foreach ($teams as $slug => $team) {
            $override = Setting::get(self::nameSettingKey($slug));
            if (is_string($override) && trim($override) !== '') {
                $teams[$slug]['name'] = trim($override);
            }
        }

        return $teams;
    }
}
