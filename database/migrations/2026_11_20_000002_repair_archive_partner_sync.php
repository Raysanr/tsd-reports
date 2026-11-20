<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgLink;
use App\Models\TsaShift;
use App\Support\DataSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair of the first ArchivePartnerSync run (2026-11-20):
 *  1. Its case-sensitive lookup (Postgres) missed the hand-made TEAM
 *     PARTNERS and created a duplicate — fold the duplicate into the original.
 *  2. It made People from TSAs the partners had REMOVED — delete those
 *     sync-made records (only if nothing was recorded against them) and
 *     unlink TSD people from removed partner TSAs.
 *  3. People who moved TSD → partner were re-resigned by the TSD sync —
 *     reactivate those still active on the partner side.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dup = Department::where('team_slug', 'partners')->first();
        $orig = $dup ? Department::whereNull('parent_id')->whereNull('team_slug')->where('id', '<', $dup->id)
            ->whereRaw('LOWER(name) LIKE ?', ['%partner%'])->orderBy('id')->first() : null;
        if ($dup && $orig) {
            Department::where('parent_id', $dup->id)->update(['parent_id' => $orig->id]);
            Employee::where('department_id', $dup->id)->update(['department_id' => $orig->id]);
            DB::table('archive_documents')->where('department_id', $dup->id)->update(['department_id' => $orig->id]);
            OrgLink::where('type', 'department')->where(fn ($q) => $q->where('parent_id', $dup->id)->orWhere('child_id', $dup->id))->delete();
            $dup->update(['team_slug' => null]);
            $dup->delete();
            $orig->update(['team_slug' => 'partners']);
        }

        try {
            $rows = DB::connection(DataSet::PARTNERS)->table('tsa_shifts')->get(['tsa_key', 'deleted_at'])->keyBy('tsa_key');
        } catch (\Throwable) {
            return; // no partners database here
        }
        if ($rows->isEmpty()) {
            return;
        }
        $activeKey = fn (?string $k) => $k !== null && isset($rows[$k]) && $rows[$k]->deleted_at === null;
        $partnerTeams = Department::where('team_slug', 'like', 'partner-%')->pluck('id')->all();

        foreach (Employee::whereNotNull('partner_tsa_key')->get() as $e) {
            if ($activeKey($e->partner_tsa_key)) {
                $leftTsd = $e->tsa_shift_id && TsaShift::withoutGlobalScopes()->whereKey($e->tsa_shift_id)->whereNotNull('deleted_at')->exists();
                if ($leftTsd && $e->status === 'resigned' && in_array($e->department_id, $partnerTeams, true)) {
                    $e->update(['status' => 'active', 'left_at' => null]);
                }
                continue;
            }

            $untouched = ! DB::table('incidents')->where('employee_id', $e->id)->exists()
                && ! DB::table('coaching_records')->where('employee_id', $e->id)->exists()
                && ! DB::table('archive_documents')->where('employee_id', $e->id)->exists();
            if ($e->tsa_shift_id === null && $untouched) {
                $e->delete();
            } else {
                $e->update(['partner_tsa_key' => null]);
            }
        }
    }

    public function down(): void {}
};
