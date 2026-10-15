<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly Cost Breakdown (explicit request, 2026-10-15: "create date picker
 * too in the cost breakdown page ... like in the projections page like
 * monthly only and can add cost breakdown too for this month"). Same 'Y-m'
 * string convention as projection_columns.month.
 *
 * Every existing row is backfilled to the current month — and because a
 * month with no saved Cost Breakdown of its own falls back to the latest
 * EARLIER month that has one (or the earliest month there is, see
 * App\Support\CostBreakdownMonths::effective()), every past day keeps
 * exactly the figures it showed before this deploy.
 */
return new class extends Migration
{
    private const TABLES = [
        'cost_breakdown_roles' => 'seed_key',
        'cost_breakdown_pools' => 'key',
        'cost_breakdown_tsa_entries' => 'tsa_id',
    ];

    public function up(): void
    {
        $month = now('Asia/Manila')->format('Y-m');

        foreach (self::TABLES as $table => $uniqueColumn) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('month', 7)->nullable()->after('id');
            });
            DB::table($table)->whereNull('month')->update(['month' => $month]);
            Schema::table($table, function (Blueprint $t) use ($uniqueColumn) {
                $t->string('month', 7)->nullable(false)->change();
                // New index first: on MySQL the old unique(tsa_id) also
                // backs the tsa_id foreign key and can't be dropped first.
                $t->unique([$uniqueColumn, 'month']);
                $t->dropUnique([$uniqueColumn]);
                $t->index('month');
            });
        }

        // Table locks become per month too — today's lock state carries
        // over to the backfilled month.
        foreach (['salary', 'pools', 'tsa'] as $lockTable) {
            $value = DB::table('settings')->where('key', "cost_breakdown_lock_{$lockTable}")->value('value');
            if ($value !== null) {
                DB::table('settings')->updateOrInsert(['key' => "cost_breakdown_lock_{$lockTable}_{$month}"], ['value' => $value]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $uniqueColumn) {
            $keep = DB::table($table)->max('month');
            DB::table($table)->where('month', '!=', $keep)->delete();
            Schema::table($table, function (Blueprint $t) use ($uniqueColumn) {
                $t->dropIndex(['month']);
                $t->dropUnique([$uniqueColumn, 'month']);
                $t->unique([$uniqueColumn]);
                $t->dropColumn('month');
            });
        }
    }
};
