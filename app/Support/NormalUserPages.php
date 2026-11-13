<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Settings → Page Access (explicit request, 2026-11-13): admins switch every
 * module and every page on or off for Normal users (TSAs).
 *
 * - Pages Normal users could already open start ON; admin-only pages
 *   (Config, Cost Breakdown, Archiving, …) start OFF. Switching an
 *   admin-only page on makes it VIEW-ONLY for Normal users: they can open
 *   and read it (GET requests), every save stays admin-only
 *   (EnsureUserHasRole asks grantsAdminPage()).
 * - A module switched off drops off the Hub and every URL under it sends a
 *   Normal user back to the Hub. Archiving starts off.
 * - A page switched off drops out of the sidebar and its URL goes to the Hub
 *   (an admin-only page that's off keeps its usual 403).
 *   Only the page routes are listed for normal pages (not their save/refresh
 *   endpoints), since pages share endpoints (Actual Income uses Expected
 *   Income's); admin pages list their GET sub-routes with a trailing *.
 * - Hub, Messages, Settings (own Appearance), Search and lead details are
 *   always open. Admins and Super Admins always see everything. Guests keep
 *   their own module rules.
 *
 * Stored on the MAIN database as overrides of the defaults, so a later
 * default change still applies to pages nobody has touched.
 */
class NormalUserPages
{
    public const SETTING_KEY = 'normal_user_page_access';

    /** module key => label. 'data' and 'partners' share the Data pages. */
    public const MODULE_LABELS = [
        'leads'    => 'TSD Leads Reports',
        'calls'    => 'TSD Call Tracker',
        'data'     => 'TSD Data Management',
        'partners' => 'Partners Data Management',
        'archive'  => 'Archiving System',
    ];

    /** Modules switched off for Normal users until an admin turns them on. */
    private const MODULES_OFF_BY_DEFAULT = ['archive'];

    /** URL segment => module (anything else is Leads Reports). */
    private const SEGMENT_MODULES = ['calls' => 'calls', 'data' => 'data', 'partners' => 'partners', 'archive' => 'archive'];

    private const ALWAYS_OPEN_ROUTES = ['hub', 'logout', 'messages.*', 'settings', 'settings.*', 'calls.settings', 'search'];

    /**
     * page group => [page key => [label, route names, admin-only?, ?calls.leads view]].
     * The 'data' group serves both Data Management modules.
     */
    public const PAGES = [
        'leads' => [
            'dashboard'          => ['Dashboard', ['dashboard'], false],
            'leads-report'       => ['Leads Report', ['leads-report', 'leads-report.drilldown'], false],
            'tsa-performance'    => ['TSA Performance', ['tsa-performance', 'tsa-performance.drilldown', 'tsa-performance.individual', 'tsa-performance.individual.product-drilldown'], false],
            'charts'             => ['Analytics', ['charts'], false],
            'rts-report'         => ['RTS / Delivered', ['rts-report', 'rts-report.drilldown'], false],
            'insights'           => ['Insights', ['insights', 'insights.*'], true],
            'tsa-management'     => ['TSA Management', ['tsa-management', 'tsa-management.*', 'hub.tsa-management'], true],
            'product-management' => ['Product Management', ['product-management', 'product-management.*', 'hub.product-management'], true],
            'user-management'    => ['User Management', ['user-management', 'user-management.*', 'hub.users'], true],
            'sync-health'        => ['Sync Health', ['sync-health', 'sync-health.*'], true],
            'audit-log'          => ['Activity Log', ['audit-log', 'audit-log.*'], true],
        ],
        'calls' => [
            'calls.dashboard'          => ['Dashboard', ['calls.dashboard'], false],
            'calls.leads'              => ['Leads', ['calls.leads.index'], false, ''],
            'calls.overdue'            => ['Overdue', ['calls.leads.index'], false, 'overdue'],
            'calls.callbacks'          => ['Callbacks', ['calls.leads.index'], false, 'callbacks'],
            'calls.unanswered'         => ['Unanswered Calls', ['calls.leads.index'], false, 'unanswered'],
            'calls.no-confirmation'    => ['No Confirmation', ['calls.leads.index'], false, 'no_confirmation'],
            'calls.call-log'           => ['Call Log', ['calls.call-log'], false],
            'calls.monitor'            => ['Monitor TSA', ['calls.monitor'], false],
            'calls.analytics'          => ['Analytics', ['calls.analytics', 'calls.analytics.*'], true],
            'calls.call-recordings'    => ['Call Recordings', ['calls.call-recordings', 'calls.call-recordings.*'], true],
            'calls.sync-health'        => ['Sync Health', ['calls.sync-health', 'calls.sync-health.*'], true],
            'calls.tsa-management'     => ['TSA Management', ['calls.tsa-management', 'calls.tsa-management.*'], true],
            'calls.round-robin-setup'  => ['Round Robin Setup', ['calls.round-robin-setup', 'calls.round-robin-setup.*'], true],
            'calls.tsa-logs'           => ['TSA Logs', ['calls.tsa-logs', 'calls.tsa-logs.*'], true],
        ],
        'data' => [
            'data.projections'             => ['Projections', ['data.projections'], false],
            'data.dsppr'                   => ['DSPPR', ['data.dsppr'], false],
            'data.tsa-sales'               => ['TSA Sales', ['data.tsa-sales'], false],
            'data.expected-income'         => ['Expected Income', ['data.expected-income'], false],
            'data.actual-income'           => ['Actual Income', ['data.actual-income'], false],
            'data.activity-log'            => ['Activity Log', ['data.activity-log'], false],
            'data.cost-breakdown'          => ['Cost Breakdown', ['data.cost-breakdown', 'data.cost-breakdown.*'], true],
            'data.actual-cost-breakdown'   => ['Actual Cost Breakdown', ['data.actual-cost-breakdown', 'data.actual-cost-breakdown.*'], true],
            'data.product-costs'           => ['Product Costs', ['data.product-costs', 'data.product-costs.*'], true],
            'data.overall.projections'     => ['Overall · Projections', ['data.overall.projections', 'data.overall.projections.*'], true],
            'data.overall.sales-summary'   => ['Overall · Sales Summary', ['data.overall.sales-summary', 'data.overall.sales-summary.*'], true],
            'data.overall.expected-income' => ['Overall · Expected Income', ['data.overall.expected-income', 'data.overall.expected-income.*'], true],
        ],
        'archive' => [
            'archive.overview'     => ['Overview', ['archive.overview'], true],
            'archive.live-tsa'     => ['TSD Scoreboard', ['archive.live-tsa', 'archive.live-tsa.*'], true],
            'archive.org-chart'    => ['Org Chart', ['archive.org-chart', 'archive.org-chart.*'], true],
            'archive.departments'  => ['Teams', ['archive.departments', 'archive.departments.*'], true],
            'archive.people'       => ['People', ['archive.people', 'archive.people.*'], true],
            'archive.incidents'    => ['Incidents & NTEs', ['archive.incidents', 'archive.incidents.*'], true],
            'archive.performance'  => ['Performance History', ['archive.performance', 'archive.performance.*'], true],
            'archive.library'      => ['TSD Documented Process', ['archive.library', 'archive.library.*'], true],
            'archive.documents'    => ['Documents', ['archive.documents', 'archive.documents.*'], true],
            'archive.records'      => ['Archived Records', ['archive.records', 'archive.records.*'], true],
            'archive.activity-log' => ['Activity Log', ['archive.activity-log', 'archive.activity-log.*'], true],
        ],
    ];

    // ---- stored overrides --------------------------------------------------

    /** @return array<string, bool> key => on/off, only where it differs from the default */
    private static function overrides(): array
    {
        $value = RequestMemo::remember('normal-user-pages', fn () => rescue(
            fn () => Setting::on(DataSet::mainConnection())->where('key', self::SETTING_KEY)->value('value'), null, false));
        $map = \is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

        return array_map('boolval', \is_array($map) ? $map : []);
    }

    /** Save a full on/off state; only differences from the defaults are kept. */
    public static function save(array $modulesOn, array $pagesOn): void
    {
        $map = [];
        foreach (array_keys(self::MODULE_LABELS) as $m) {
            $on = \in_array($m, $modulesOn, true);
            if ($on !== self::moduleDefault($m)) {
                $map['module.' . $m] = $on;
            }
        }
        foreach (self::allPages() as $key => $page) {
            $on = \in_array($key, $pagesOn, true);
            if ($on !== !$page[2]) {
                $map[$key] = $on;
            }
        }

        Setting::on(DataSet::mainConnection())->updateOrCreate(['key' => self::SETTING_KEY], ['value' => json_encode((object) $map)]);
        RequestMemo::flush();
    }

    // ---- lookups -----------------------------------------------------------

    /** @return array<string, array> every page key => definition */
    public static function allPages(): array
    {
        return array_merge(...array_values(self::PAGES));
    }

    public static function allKeys(): array
    {
        return array_keys(self::allPages());
    }

    private static function moduleDefault(string $module): bool
    {
        return !\in_array($module, self::MODULES_OFF_BY_DEFAULT, true);
    }

    /** Is this module on for Normal users? */
    public static function moduleOn(string $module): bool
    {
        return self::overrides()['module.' . $module] ?? self::moduleDefault($module);
    }

    /** Is this page on for Normal users (ignoring its module)? */
    public static function pageOn(string $key): bool
    {
        $page = self::allPages()[$key] ?? null;

        return $page !== null && (self::overrides()[$key] ?? !$page[2]);
    }

    public static function isAdminOnly(string $key): bool
    {
        return (bool) (self::allPages()[$key][2] ?? true);
    }

    /** Module a page key belongs to — Data pages follow the current dataset. */
    private static function moduleOfKey(string $key): string
    {
        foreach (self::PAGES as $group => $pages) {
            if (isset($pages[$key])) {
                return $group === 'data' && DataSet::isPartners() ? 'partners' : $group;
            }
        }

        return 'leads';
    }

    /** Sidebar/Hub check for a link to this page. */
    public static function canSee(string $key, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user === null) {
            return false;
        }

        return match ($user->role) {
            'super_admin', 'admin' => true,
            'normal' => self::moduleOn(self::moduleOfKey($key)) && self::pageOn($key),
            default  => !self::isAdminOnly($key), // guests: same links as before
        };
    }

    /** True when this user can see at least one of these pages (section headers). */
    public static function anyVisible(array $keys): bool
    {
        foreach ($keys as $key) {
            if (self::canSee($key)) {
                return true;
            }
        }

        return false;
    }

    /** Hub card check for a whole module (Archiving for Normal users). */
    public static function canSeeModule(string $module, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return match ($user?->role) {
            'super_admin', 'admin' => true,
            'normal' => self::moduleOn($module),
            default  => false,
        };
    }

    /** Data Management's landing page: the first one this user can see. */
    public static function firstVisibleDataPage(): string
    {
        foreach (array_keys(self::PAGES['data']) as $key) {
            if (self::canSee($key)) {
                return $key;
            }
        }

        return 'hub';
    }

    /** Archiving's landing page for this user. */
    public static function firstVisibleArchivePage(): ?string
    {
        foreach (array_keys(self::PAGES['archive']) as $key) {
            if (self::canSee($key)) {
                return $key;
            }
        }

        return null;
    }

    // ---- request checks ----------------------------------------------------

    /** [key, definition] of the page this request opens, or null. */
    private static function pageFor(Request $request): ?array
    {
        $view = $request->string('view')->toString();
        foreach (self::allPages() as $key => $page) {
            if ($request->routeIs(...$page[1]) && (!isset($page[3]) || $page[3] === $view)) {
                return [$key, $page];
            }
        }

        return null;
    }

    /** What blocks a Normal user from this request ('module:x' or a page key), else null. */
    public static function blockedPage(Request $request): ?string
    {
        if ($request->user()?->role !== 'normal' || $request->routeIs(...self::ALWAYS_OPEN_ROUTES)) {
            return null;
        }

        // Admin-only pages are left to the role check (403 unless switched on).
        [$key, $page] = self::pageFor($request) ?? [null, null];
        if ($key !== null && $page[2]) {
            return null;
        }

        $module = self::SEGMENT_MODULES[$request->segment(1)] ?? 'leads';
        if (!self::moduleOn($module)) {
            return 'module:' . $module;
        }

        return $key !== null && !self::pageOn($key) ? $key : null;
    }

    /**
     * True when a Normal user may VIEW this admin-only page because an admin
     * switched it on (EnsureUserHasRole). Never for a save (non-GET).
     */
    public static function grantsAdminPage(Request $request): bool
    {
        if ($request->user()?->role !== 'normal' || !$request->isMethodSafe()) {
            return false;
        }
        $module = self::SEGMENT_MODULES[$request->segment(1)] ?? 'leads';
        [$key, $page] = self::pageFor($request) ?? [null, null];

        return $key !== null && $page[2] && self::moduleOn($module) && self::pageOn($key);
    }
}
