<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Models\TsaRestDay;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TsaManagementController extends Controller
{
    public function index()
    {
        $teamsConfig = config('teams', []);
        $shifts      = TsaShift::with('restDays')->orderBy('sort_order')->get();

        // Group TSAs by team for display, using each configured team's real
        // 'order_team' string (e.g. "SH Naturals") as the grouping key.
        $teamGroups = collect($teamsConfig)->map(function ($team) use ($shifts) {
            return [
                'name'  => $team['name'],
                'shifts' => $shifts->where('team', $team['order_team'])->values(),
            ];
        });

        // Any TSA whose team doesn't match a configured team (data issue / manual edit)
        $unassigned = $shifts->reject(fn($s) => collect($teamsConfig)->pluck('order_team')->contains($s->team));

        $calendar = $this->buildCalendar($shifts, request('month'));

        $trashedShifts = TsaShift::onlyTrashed()->orderBy('display_name')->get();

        return view('tsa-management', compact('teamGroups', 'teamsConfig', 'unassigned', 'calendar', 'shifts', 'trashedShifts'));
    }

    /**
     * Builds the month calendar grid for the rest-day sidebar: which TSAs are off on
     * each date of the requested (or current) month. Falls back to the current month
     * for a missing/invalid ?month= value rather than erroring.
     */
    private function buildCalendar(Collection $shifts, ?string $monthParam): array
    {
        $month = null;
        if ($monthParam) {
            try {
                $month = Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth();
            } catch (\Throwable $e) {
                $month = null;
            }
        }
        $month ??= now('Asia/Manila')->startOfMonth();

        $days   = [];
        $cursor = $month->copy();
        while ($cursor->month === $month->month) {
            $offTsas = $shifts
                ->filter(fn($shift) => $shift->isOffOn($cursor))
                ->map(fn($shift) => ['tsa_key' => $shift->tsa_key, 'initials' => strtoupper(substr($shift->display_name, 0, 2))])
                ->values();

            $days[] = [
                'date'     => $cursor->toDateString(),
                'day'      => $cursor->day,
                'off_tsas' => $offTsas,
            ];
            $cursor->addDay();
        }

        return [
            'month_label'    => $month->format('F Y'),
            'prev_month'     => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next_month'     => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'leading_blanks' => $month->copy()->startOfMonth()->dayOfWeek,
            'days'           => $days,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validateTsa($request);

        $tsaKey = $this->generateUniqueKey($data['display_name']);
        $nextSort = (int) (TsaShift::max('sort_order') ?? 0) + 1;

        TsaShift::create([
            'tsa_key'          => $tsaKey,
            'pos_user_id'      => $data['pos_user_id'] ?? null,
            'display_name'     => $data['display_name'],
            'team'             => $data['team'],
            'tag_keywords'     => $this->buildTagKeywords($tsaKey, $data['extra_keywords'] ?? ''),
            // Fresh record: only set if a real POS account was picked, otherwise leave null.
            'seller_keywords'  => !empty($data['pos_user_id']) ? strtolower($data['display_name']) : null,
            'rest_day_of_week' => $data['rest_day_of_week'] ?? null,
            'sort_order'       => $nextSort,
        ]);

        return redirect()->route('tsa-management')
            ->with('success', "Added \"{$data['display_name']}\" to {$data['team']}.");
    }

    public function update(Request $request, TsaShift $tsaShift)
    {
        $data = $this->validateTsa($request);

        // Only overwrite seller_keywords when a *new* POS account was actually picked
        // this time — otherwise preserve whatever was there (e.g. a hand-set legacy
        // value like Kathleen's "sh kathleen"), so opening Edit and just tweaking the
        // shift or team doesn't silently wipe out existing seller-matching data.
        $sellerKeywords = $tsaShift->seller_keywords;
        if (!empty($data['pos_user_id']) && $data['pos_user_id'] !== $tsaShift->pos_user_id) {
            $sellerKeywords = strtolower($data['display_name']);
        }

        $tsaShift->update([
            'pos_user_id'      => $data['pos_user_id'] ?? $tsaShift->pos_user_id,
            'display_name'     => $data['display_name'],
            'team'             => $data['team'],
            'tag_keywords'     => $this->buildTagKeywords($tsaShift->tsa_key, $data['extra_keywords'] ?? ''),
            'seller_keywords'  => $sellerKeywords,
            'rest_day_of_week' => $data['rest_day_of_week'] ?? null,
        ]);

        return redirect()->route('tsa-management')
            ->with('success', "Updated \"{$data['display_name']}\".");
    }

    public function destroy(TsaShift $tsaShift)
    {
        $name = $tsaShift->display_name;
        $tsaShift->delete();

        return redirect()->route('tsa-management')
            ->with('success', "Removed \"{$name}\" from the roster.");
    }

    // Plain {id} param (not {tsaShift}) is deliberate — implicit route-model-binding
    // excludes soft-deleted rows by default, so a {tsaShift}-typed param would 404
    // on exactly the trashed records this route needs to find. Resolved manually.
    public function restore(int $id)
    {
        $tsaShift = TsaShift::onlyTrashed()->findOrFail($id);
        $tsaShift->restore();

        return redirect()->route('tsa-management')
            ->with('success', "Restored \"{$tsaShift->display_name}\".");
    }

    public function bulk(Request $request)
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        $data = $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:tsa_shifts,id',
            'action' => 'required|in:delete,move',
            'team'   => 'required_if:action,move|nullable|string|in:' . implode(',', $validTeams),
        ]);

        $count = count($data['ids']);
        $noun  = $count === 1 ? 'TSA' : 'TSAs';

        switch ($data['action']) {
            case 'move':
                TsaShift::whereIn('id', $data['ids'])->update(['team' => $data['team']]);
                $teamName = collect($teamsConfig)->firstWhere('order_team', $data['team'])['name'] ?? $data['team'];
                $message = "Moved {$count} {$noun} to {$teamName}.";
                break;
            case 'delete':
                TsaShift::whereIn('id', $data['ids'])->delete();
                $message = "Removed {$count} {$noun}.";
                break;
        }

        return redirect()->route('tsa-management')->with('success', $message);
    }

    /**
     * Persists which TSAs are off on $date. Only writes a tsa_rest_days row when
     * the submitted state actually differs from what rest_day_of_week alone would
     * produce — matching or removed rows are deleted so the table only ever holds
     * genuine exceptions (see the migration's comment on tsa_rest_days.is_off).
     */
    public function saveRestDays(Request $request, string $date)
    {
        $parsedDate  = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $checkedKeys = $request->input('tsas', []);
        $shifts      = TsaShift::with('restDays')->get();

        foreach ($shifts as $shift) {
            $defaultOff = $shift->rest_day_of_week !== null
                && strtolower($parsedDate->format('l')) === $shift->rest_day_of_week;
            $desiredOff = in_array($shift->tsa_key, $checkedKeys, true);

            $existing = $shift->restDays->first(
                fn (TsaRestDay $r) => $r->date->toDateString() === $parsedDate->toDateString()
            );

            if ($desiredOff === $defaultOff) {
                $existing?->delete();
            } elseif ($existing) {
                $existing->update(['is_off' => $desiredOff]);
            } else {
                TsaRestDay::create([
                    'tsa_shift_id' => $shift->id,
                    'date'         => $parsedDate->toDateString(),
                    'is_off'       => $desiredOff,
                ]);
            }
        }

        return redirect()->route('tsa-management', ['month' => $parsedDate->format('Y-m')])
            ->with('success', "Updated rest days for {$parsedDate->format('M j, Y')}.");
    }

    /** AJAX — search the real Pancake POS user list for the Add/Edit TSA picker. */
    public function searchPosUsers(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $users = $this->fetchPosUsers();

        if ($query !== '') {
            $users = $users->filter(fn($u) => stripos($u['name'], $query) !== false)->values();
        }

        return response()->json($users->take(25)->values());
    }

    /** AJAX — search the real Pancake tag list for the "Also matches" picker. */
    public function searchTags(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $tags  = $this->fetchAllTags();

        if ($query !== '') {
            $tags = $tags->filter(fn($t) => stripos($t['name'], $query) !== false)->values();
        }

        return response()->json($tags->take(50)->values());
    }

    /**
     * Merges the shop's full tag list with recent usage counts, so the picker is
     * both complete and sorted by relevance:
     *
     *  - fetchShopTags(): every tag that exists in Pancake for this shop (used or
     *    not — GET /shops/{id}/orders/tags is shop-wide, not scanned from orders).
     *  - fetchRecentTags(): how often each tag actually appears on recent orders,
     *    used only to rank results — the shop endpoint has no usage/frequency data.
     */
    private function fetchAllTags(): Collection
    {
        $shopTags = $this->fetchShopTags();
        $usage    = $this->fetchRecentTags();

        $countsByUpper = $usage->keyBy(fn($t) => strtoupper($t['name']));

        $merged = $shopTags->map(function ($tag) use ($countsByUpper) {
            $seen = $countsByUpper->get(strtoupper($tag['name']));
            return ['name' => $tag['name'], 'count' => $seen['count'] ?? 0];
        });

        $shopTagsUpper = $shopTags->pluck('name')->map(fn($n) => strtoupper($n))->flip();
        $merged = $merged->concat(
            $usage->reject(fn($t) => $shopTagsUpper->has(strtoupper($t['name'])))
        );

        return $merged->sortByDesc('count')->values();
    }

    /**
     * The shop's complete tag list straight from Pancake (GET /shops/{id}/orders/tags,
     * documented at api-docs.pancake.vn) — every tag that exists for this shop,
     * including ones never applied to an order yet. Cheap enough (one call, no
     * pagination) to cache short-term like fetchPosUsers(), unlike the per-page
     * "page.tags" data embedded in order responses, which is scoped to whichever
     * Facebook page happens to own a given order and can go stale/pruned over time.
     */
    private function fetchShopTags(): Collection
    {
        return Cache::remember('pancake_shop_tags', 600, function () {
            $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
            $shopId = Setting::get('shop_id', '');

            if (empty($apiKey) || empty($shopId)) {
                return collect();
            }

            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(20)
                ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/orders/tags", ['api_key' => $apiKey]);

            if (!$response->successful()) {
                return collect();
            }

            return collect($response->json('data', []))
                ->map(fn($tag) => ['name' => trim((string) ($tag['name'] ?? ''))])
                ->filter(fn($tag) => $tag['name'] !== '')
                ->unique(fn($tag) => strtoupper($tag['name']))
                ->values();
        });
    }

    /**
     * Distinct tags actually seen on orders in the last 120 days, most-used first —
     * gives the "Also matches" picker real Pancake tags instead of free-typed guesses.
     * Scoped to a recent window (not the full ~148k-row table) so the scan + cache
     * stays cheap; older/retired tags aren't useful for new keyword matching anyway.
     */
    private function fetchRecentTags(): Collection
    {
        return Cache::remember('pancake_recent_tags', 600, function () {
            $counts = [];

            Order::where('pancake_created_at', '>=', now()->subDays(120))
                ->whereNotNull('raw_tags')
                ->select('raw_tags')
                ->orderBy('id')
                ->chunk(2000, function ($rows) use (&$counts) {
                    foreach ($rows as $row) {
                        foreach ($row->raw_tags ?? [] as $tag) {
                            $tag = trim((string) $tag);
                            if ($tag === '') continue;
                            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                        }
                    }
                });

            arsort($counts);

            return collect($counts)->map(fn($count, $name) => ['name' => $name, 'count' => $count])->values();
        });
    }

    /** Fetch + cache the shop's full POS user list (name/id pairs), noisy system rows filtered out. */
    private function fetchPosUsers()
    {
        return Cache::remember('pancake_pos_users', 600, function () {
            $apiKey = Setting::get('pancake_api_key', env('PANCAKE_API_KEY', ''));
            $shopId = Setting::get('shop_id', '');

            if (empty($apiKey) || empty($shopId)) {
                return collect();
            }

            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(15)
                ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/users", ['api_key' => $apiKey]);

            if (!$response->successful()) {
                return collect();
            }

            return collect($response->json('data', []))
                ->map(fn($row) => [
                    'id'   => $row['id'] ?? null,
                    'name' => trim($row['user']['name'] ?? ''),
                ])
                ->filter(fn($u) => $u['id'] && $u['name'] !== '' && !str_contains(strtoupper($u['name']), 'API_CONNECTION'))
                ->unique('id')
                ->sortBy('name')
                ->values();
        });
    }

    private function validateTsa(Request $request): array
    {
        $teamsConfig = config('teams', []);
        $validTeams  = collect($teamsConfig)->pluck('order_team')->all();

        return $request->validate([
            'display_name'     => 'required|string|max:100',
            'team'             => 'required|string|in:' . implode(',', $validTeams),
            'pos_user_id'      => 'nullable|string|max:100',
            'extra_keywords'   => 'nullable|string|max:255',
            'rest_day_of_week' => 'nullable|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
        ]);
    }

    /** tag_keywords always includes the TSA's first name (tsa_key) plus any extras typed in. */
    private function buildTagKeywords(string $tsaKey, string $extra): string
    {
        $tagKeywords = array_unique(array_filter(array_map('trim', array_merge(
            [strtoupper($tsaKey)],
            explode(',', strtoupper($extra))
        ))));

        return implode(',', $tagKeywords);
    }

    /** First word of the name, uniquified against existing tsa_key values. */
    private function generateUniqueKey(string $displayName): string
    {
        $base = preg_replace('/[^A-Za-z]/', '', strtok(trim($displayName), ' ')) ?: 'Tsa';
        $base = ucfirst(strtolower($base));

        $key = $base;
        $i   = 2;
        while (TsaShift::where('tsa_key', $key)->exists()) {
            $key = $base . $i;
            $i++;
        }

        return $key;
    }
}
