<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TsaManagementController extends Controller
{
    public function index()
    {
        $teamsConfig = config('teams', []);
        $shifts      = TsaShift::orderBy('sort_order')->get();

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

        return view('tsa-management', compact('teamGroups', 'teamsConfig', 'unassigned'));
    }

    public function store(Request $request)
    {
        $data = $this->validateTsa($request);

        $tsaKey = $this->generateUniqueKey($data['display_name']);
        $nextSort = (int) (TsaShift::max('sort_order') ?? 0) + 1;

        TsaShift::create([
            'tsa_key'         => $tsaKey,
            'pos_user_id'     => $data['pos_user_id'] ?? null,
            'display_name'    => $data['display_name'],
            'team'            => $data['team'],
            'tag_keywords'    => $this->buildTagKeywords($tsaKey, $data['extra_keywords'] ?? ''),
            // Fresh record: only set if a real POS account was picked, otherwise leave null.
            'seller_keywords' => !empty($data['pos_user_id']) ? strtolower($data['display_name']) : null,
            'sort_order'      => $nextSort,
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
            'pos_user_id'     => $data['pos_user_id'] ?? $tsaShift->pos_user_id,
            'display_name'    => $data['display_name'],
            'team'            => $data['team'],
            'tag_keywords'    => $this->buildTagKeywords($tsaShift->tsa_key, $data['extra_keywords'] ?? ''),
            'seller_keywords' => $sellerKeywords,
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
            'display_name'   => 'required|string|max:100',
            'team'           => 'required|string|in:' . implode(',', $validTeams),
            'pos_user_id'    => 'nullable|string|max:100',
            'extra_keywords' => 'nullable|string|max:255',
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
