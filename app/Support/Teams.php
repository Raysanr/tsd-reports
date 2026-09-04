<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TeamNameHistory;
use Illuminate\Support\Carbon;

/**
 * config('teams') plus an editable, DATED display-name overlay — explicit
 * request, 2026-09-02: "is it possible that the team sh naturals and
 * eyecare is editable," then 2026-09-04: "i wanna know that if today 12
 * midnight transition the team opening and closing ... backtrack the data
 * like yesterday it is sh naturals and eyecare" — a plain "current name"
 * override renamed every date at once, including the past, which broke
 * exactly this expectation: renaming today made yesterday's Dashboard show
 * the NEW name too. Deliberately keeps the array KEY (e.g. 'sh-naturals')
 * and 'order_team' (the literal string synced from Pancake into
 * orders.team/tsa_shifts.team — see config/teams.php's own doc comment)
 * fixed and NOT editable — every route/session/query-string 'team' param
 * throughout the app is keyed by the slug, and order_team has to match real
 * synced data exactly. Only 'name' (the display label shown in the UI) is
 * overridable, and now DATED: every save inserts a new TeamNameHistory row
 * (slug, name, effective_from) rather than overwriting a single "current"
 * value — "the current name" is just the special case of "the name
 * effective as of today," resolved the exact same way as any past date.
 * Team CREATION stays out of scope, same explicit decision as
 * docs/superpowers/specs/2026-07-06-product-management-design.md made for
 * Product Management's own team handling.
 */
class Teams
{
    /** Legacy Setting key from before dated rename history existed — kept
     *  only so nameFor()'s own fallback (see below) can still recognize an
     *  override saved before this migration, without needing a one-time
     *  data-migration script for what's currently just 2 fixed teams. New
     *  saves always go through TeamNameHistory instead (see
     *  SettingsController::saveTeamNames()). */
    public static function nameSettingKey(string $slug): string
    {
        return "team_name_{$slug}";
    }

    /** Same shape as config('teams') (slug => ['name' => ..., 'order_team' =>
     *  ...]), with the name effective as of TODAY applied — every existing
     *  config('teams') call site can swap to this with no other change,
     *  since the returned structure is identical. Use this ONLY where no
     *  specific report date is in scope (e.g. a plain admin dropdown/filter
     *  with nothing to backdate against, like Settings' own team-name
     *  input, or TsaManagementController::store()'s validation list) — any
     *  call site rendering data for a specific date/range should use
     *  nameFor()/nameForRange() below instead, so a past date keeps
     *  showing the name that was actually in effect then. */
    public static function config(): array
    {
        $teams = config('teams', []);

        foreach ($teams as $slug => $team) {
            $teams[$slug]['name'] = self::nameFor($slug, today());
        }

        return $teams;
    }

    /** The display name in effect for $slug on $date — the latest
     *  TeamNameHistory row whose effective_from is on or before $date,
     *  falling back to the legacy single-value Setting override (pre-dates
     *  this table; treated as always having been in effect, since there's
     *  no historical record of when IT started), then to config('teams')'s
     *  own bare default when neither exists. */
    public static function nameFor(string $slug, \DateTimeInterface $date): string
    {
        $default = config("teams.{$slug}.name");
        if ($default === null) {
            return $slug;
        }

        $row = TeamNameHistory::where('slug', $slug)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if ($row) {
            return $row->name;
        }

        $legacy = Setting::get(self::nameSettingKey($slug));
        if (is_string($legacy) && trim($legacy) !== '') {
            return trim($legacy);
        }

        return $default;
    }

    /** Reverse lookup: the slug whose fixed order_team value equals
     *  $orderTeam — needed because nearly every real call site (TsaShift.
     *  team, Order.team) only ever has the order_team STRING in hand (e.g.
     *  "SH Naturals"), never the config array key ("sh-naturals") nameFor()/
     *  nameForRange() actually key on. Null when $orderTeam matches no
     *  configured team at all (e.g. a stale/unmapped value) — callers
     *  should fall back to the bare order_team string itself in that case,
     *  same "never show a false empty state for real data" convention used
     *  elsewhere in this app. */
    public static function slugForOrderTeam(string $orderTeam): ?string
    {
        foreach (config('teams', []) as $slug => $team) {
            if (($team['order_team'] ?? null) === $orderTeam) {
                return $slug;
            }
        }
        return null;
    }

    /** Convenience wrapper for the overwhelmingly common call-site shape:
     *  "I have a TsaShift/Order's own order_team STRING, resolve its
     *  effective-today name" — same nameFor() semantics, just skipping the
     *  slug lookup ceremony at every call site. Falls back to $orderTeam
     *  itself unchanged when it matches no configured team. */
    public static function nameForOrderTeam(string $orderTeam, \DateTimeInterface $date): string
    {
        $slug = self::slugForOrderTeam($orderTeam);
        return $slug ? self::nameFor($slug, $date) : $orderTeam;
    }

    /** Same convenience as nameForOrderTeam() above, for a whole range. */
    public static function nameForOrderTeamRange(string $orderTeam, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $slug = self::slugForOrderTeam($orderTeam);
        return $slug ? self::nameForRange($slug, $from, $to) : $orderTeam;
    }

    /** The label to show for $slug across a whole [$from, $to] range —
     *  explicit request, 2026-09-04: a range straddling a rename shows BOTH
     *  names ("Old / New"), not just picking one arbitrarily, since neither
     *  alone would honestly describe every day in that range. Only ever
     *  returns a single name when every day in the range resolves to the
     *  exact same name (the overwhelmingly common case: no rename took
     *  effect inside this specific range at all). */
    public static function nameForRange(string $slug, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $fromName = self::nameFor($slug, $from);
        $toName   = self::nameFor($slug, $to);

        if ($fromName === $toName) {
            return $fromName;
        }

        // A rename could have happened, reverted, then happened again more
        // than once inside a long range — collect every DISTINCT name any
        // day in [$from, $to] actually resolves to (not just the two
        // endpoints) so the combined label never silently drops a name a
        // rename briefly used mid-range. Only rows whose effective_from
        // falls WITHIN the range itself count as "took effect during this
        // range" — an earlier rename (effective_from < $from) is already
        // captured by $fromName above, and one that started before $from
        // but got superseded before $from even began contributed nothing
        // any day in [$from, $to] ever actually showed.
        $namesStartedInRange = TeamNameHistory::where('slug', $slug)
            ->whereDate('effective_from', '>=', $from)
            ->whereDate('effective_from', '<=', $to)
            ->orderBy('effective_from')
            ->pluck('name');

        $names = $namesStartedInRange
            ->prepend($fromName)
            ->push($toName)
            ->unique()
            ->values();

        return $names->implode(' / ');
    }
}
