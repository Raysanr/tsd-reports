<?php

use App\Support\DataSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A TSA's first working day (explicit request, 2026-11-17): every report
 * shows a newly added TSA from the day she was added onwards, never on the
 * days before it. Empty = always on the roster (every TSA added before this,
 * and every synced partner TSA).
 *
 * TSAs added this month (Nov 2026, on the main data set, after the first
 * roster and with no sale before it) get the day they were added; older
 * ones stay empty, so no past report changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tsa_shifts', 'first_day')) {
            Schema::table('tsa_shifts', function (Blueprint $table) {
                $table->date('first_day')->nullable()->after('deleted_at');
            });
        }

        if (Schema::getConnection()->getName() === DataSet::PARTNERS) {
            return;
        }

        // Never the first roster (seeded together, e.g. a fresh database),
        // and never hide a sale she made before the day she was added.
        $seededAt = DB::table('tsa_shifts')->min('created_at');
        DB::table('tsa_shifts')->whereNull('first_day')->where('created_at', '>=', '2026-11-01 00:00:00')
            ->where('created_at', '>', $seededAt)
            ->orderBy('id')->get(['id', 'tsa_key', 'created_at'])
            ->each(function ($tsa) {
                $day = substr((string) $tsa->created_at, 0, 10);
                $soldBefore = Schema::hasTable('orders') && DB::table('orders')
                    ->where('tsa_name', $tsa->tsa_key)->where('pancake_created_at', '<', $day . ' 00:00:00')->exists();
                if (!$soldBefore) {
                    DB::table('tsa_shifts')->where('id', $tsa->id)->update(['first_day' => $day]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('first_day');
        });
    }
};
