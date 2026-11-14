<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Support\CostBreakdownMonths;
use App\Support\CostBreakdownVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Telesales payroll (MC) figures for the sales report, from TSD's Cost
 * Breakdown (Data Management, TSD only). Two endpoints, separate token:
 *   GET /api/external/v1/tsd/mc/summary?month=YYYY-MM  (no names)
 *   GET /api/external/v1/tsd/mc/roster?month=YYYY-MM   (names + salaries)
 * source=budget (Cost Breakdown, default) or actual (Actual Cost Breakdown).
 * A month without its own saved breakdown uses the latest earlier month,
 * same as the app's own pages ('sourceMonth' says which). Nothing here is
 * written to the logs.
 */
class TelesalesMcController extends Controller
{
    /** Cost Breakdown pool key => API field, for the "other monthly lines". */
    private const OTHER_LINES = [
        'hmo_expense'             => 'hmo',
        'communication_allowance' => 'communicationAllowance',
        'managers_allowance'      => 'managersAllowance',
        'birthday_cake_allowance' => 'birthdayCake',
        'miscellaneous_expenses'  => 'miscellaneous',
        'product_research'        => 'productResearch',
    ];

    /** Roles shared with other departments (seed_key): only
     *  services.telesales_api.mc_shared_exec_pct of their salary is charged. */
    private const SHARED_EXEC_ROLES = ['ceo', 'sales_director'];

    private static function allocationPct(CostBreakdownRole $role): float
    {
        return in_array($role->seed_key, self::SHARED_EXEC_ROLES, true)
            ? (float) config('services.telesales_api.mc_shared_exec_pct', 100)
            : 100.0;
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->forMonth($request, function (string $month, string $sourceMonth, string $variant) {
            $roles = $this->roles($sourceMonth);
            $tsas = $this->tsaEntries($sourceMonth);
            $pools = CostBreakdownPool::where('month', $sourceMonth)->pluck('amount', 'key');
            $pool = fn (string $key) => round((float) ($pools[$key] ?? 0), 2);

            $otherLines = [];
            foreach (self::OTHER_LINES as $key => $field) {
                $otherLines[$field] = $pool($key);
            }

            return [
                'month'          => $month,
                'sourceMonth'    => $sourceMonth,
                'source'         => $variant,
                'headcount'      => $roles->count() + $tsas->count(),
                'salaries'       => round((float) $roles->sum(fn (CostBreakdownRole $r) => $r->base_salary * self::allocationPct($r) / 100) + (float) $tsas->sum('base_salary'), 2),
                'thirteenthMonth' => $pool('thirteenth_month_allowance'),
                'sil'            => $pool('sil'),
                'govBenefits'    => $pool('government_benefits'),
                'govBreakdown'   => null,
                'otherLines'     => $otherLines,
                'updatedAt'      => $this->updatedAt($sourceMonth),
            ];
        });
    }

    public function roster(Request $request): JsonResponse
    {
        return $this->forMonth($request, function (string $month, string $sourceMonth, string $variant) {
            $rows = $this->roles($sourceMonth)->map(fn (CostBreakdownRole $role) => [
                'position'      => $role->label,
                'name'          => $role->person_name,
                'group'         => self::groupOf($role->overhead_group),
                'team'          => $role->team,
                'monthlySalary' => round((float) $role->base_salary, 2),
                'allocationPct' => round(self::allocationPct($role), 2),
                'status'        => 'active',
                'startDate'     => null,
                'endDate'       => null,
            ]);

            $shifts = TsaShift::withTrashed()->whereIn('id', $this->tsaEntries($sourceMonth)->pluck('tsa_id'))->get()->keyBy('id');
            $rows = $rows->concat($this->tsaEntries($sourceMonth)->map(function (CostBreakdownTsaEntry $entry) use ($shifts) {
                $tsa = $shifts->get($entry->tsa_id);

                return [
                    'position'      => 'Telesales Agent',
                    'name'          => $tsa?->display_name,
                    'group'         => 'sales team',
                    'team'          => $tsa?->team,
                    'monthlySalary' => round((float) $entry->base_salary, 2),
                    'allocationPct' => 100,
                    'status'        => $tsa?->trashed() ? 'resigned' : 'active',
                    'startDate'     => null,
                    'endDate'       => $tsa?->deleted_at?->toDateString(),
                ];
            }));

            return [
                'month'       => $month,
                'sourceMonth' => $sourceMonth,
                'source'      => $variant,
                'employees'   => $rows->values()->all(),
                'updatedAt'   => $this->updatedAt($sourceMonth),
            ];
        });
    }

    private function forMonth(Request $request, \Closure $build): JsonResponse
    {
        $data = $request->validate([
            'month'  => ['required', 'date_format:Y-m'],
            'source' => ['nullable', 'in:budget,actual'],
        ]);
        $variant = $data['source'] ?? CostBreakdownVariant::BUDGET;

        return CostBreakdownVariant::using($variant, function () use ($data, $variant, $build) {
            $earlier = array_filter(CostBreakdownMonths::existing(), fn ($m) => $m <= $data['month']);
            if ($earlier === []) {
                return response()->json(['error' => 'No Cost Breakdown for this month'], 404);
            }

            return response()->json($build($data['month'], max($earlier), $variant));
        });
    }

    private function roles(string $month): Collection
    {
        return CostBreakdownRole::where('month', $month)->orderBy('sort_order')->get();
    }

    /** TSAs with a salary that month (0 = not on payroll yet). */
    private function tsaEntries(string $month): Collection
    {
        return CostBreakdownTsaEntry::where('month', $month)->where('base_salary', '>', 0)->orderBy('id')->get();
    }

    private static function groupOf(?string $overheadGroup): string
    {
        return match (true) {
            $overheadGroup === 'executive'                       => 'leadership',
            $overheadGroup === 'support'                         => 'support',
            str_starts_with((string) $overheadGroup, 'supervisor') => 'supervisor',
            default                                              => 'other',
        };
    }

    private function updatedAt(string $month): ?string
    {
        $latest = collect([
            CostBreakdownRole::withTrashed()->where('month', $month)->max('updated_at'),
            CostBreakdownTsaEntry::where('month', $month)->max('updated_at'),
            CostBreakdownPool::where('month', $month)->max('updated_at'),
        ])->filter()->max();

        return $latest ? \Illuminate\Support\Carbon::parse($latest)->toIso8601String() : null;
    }
}
