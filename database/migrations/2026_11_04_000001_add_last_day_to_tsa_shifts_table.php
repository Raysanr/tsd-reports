<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A removed TSA's last working day (explicit request, 2026-11-04): every
 * report shows her up to and including it, and from the next day she's gone.
 * Empty = her removal date.
 *
 * Already-removed TSAs get the day the old Data Management rule implied:
 * the removal date if she had orders that month before her removal,
 * otherwise the last day of the month before (e.g. Marsha, removed Nov 2
 * with no November orders → Oct 31).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tsa_shifts', 'last_day')) {
            Schema::table('tsa_shifts', function (Blueprint $table) {
                $table->date('last_day')->nullable()->after('deleted_at');
            });
        }

        DB::table('tsa_shifts')->whereNotNull('deleted_at')->whereNull('last_day')->orderBy('id')->get(['id', 'tsa_key', 'deleted_at'])
            ->each(function ($tsa) {
                $removed = Carbon::parse($tsa->deleted_at);
                $monthStart = $removed->copy()->startOfMonth();
                $soldThatMonth = Schema::hasTable('orders') && DB::table('orders')
                    ->where('tsa_name', $tsa->tsa_key)
                    ->where('pancake_created_at', '>=', $monthStart->toDateTimeString())
                    ->where('pancake_created_at', '<', $removed->toDateTimeString())
                    ->exists();

                DB::table('tsa_shifts')->where('id', $tsa->id)->update([
                    'last_day' => ($soldThatMonth ? $removed : $monthStart->subDay())->toDateString(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('last_day');
        });
    }
};
