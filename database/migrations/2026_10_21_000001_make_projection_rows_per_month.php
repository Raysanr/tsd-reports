<?php

use App\Support\ProjectionMonth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projections rows become per month (explicit request, 2026-10-21). The
 * existing shared rows, order and rates are copied into every month that
 * already has projections (plus the current month), so nothing changes on
 * screen — from now on each month edits only its own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_custom_rows', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->string('month', 7)->nullable()->after('id');
            $table->unique(['month', 'key']);
        });
        Schema::table('row_sort_orders', function (Blueprint $table) {
            $table->dropUnique(['row_key', 'section']);
            $table->string('month', 7)->nullable()->after('id');
            $table->unique(['month', 'row_key', 'section']);
        });

        $months = DB::table('projection_columns')->whereNotNull('month')->distinct()->pluck('month')
            ->push(now()->format('Y-m'))->unique();
        foreach ($months as $month) {
            ProjectionMonth::copy(null, $month);
        }

        DB::table('projection_custom_rows')->whereNull('month')->delete();
        DB::table('row_sort_orders')->whereNull('month')->delete();
        DB::table('settings')->where('key', 'like', 'projection_rate.%')->get()
            ->filter(fn ($s) => substr_count($s->key, '.') === 1)
            ->each(fn ($s) => DB::table('settings')->where('id', $s->id)->delete());
    }

    public function down(): void
    {
        // Keep the latest month as the shared set again.
        $latest = DB::table('row_sort_orders')->max('month');
        if ($latest) {
            DB::table('projection_custom_rows')->where('month', '!=', $latest)->delete();
            DB::table('row_sort_orders')->where('month', '!=', $latest)->delete();
            foreach (DB::table('settings')->where('key', 'like', "projection_rate.{$latest}.%")->get() as $s) {
                DB::table('settings')->updateOrInsert(['key' => 'projection_rate.' . substr($s->key, strlen("projection_rate.{$latest}."))], ['value' => $s->value]);
            }
            DB::table('settings')->where('key', 'like', 'projection_rate.____-__.%')->delete();
        }
        Schema::table('projection_custom_rows', function (Blueprint $table) {
            $table->dropUnique(['month', 'key']);
            $table->dropColumn('month');
            $table->unique(['key']);
        });
        Schema::table('row_sort_orders', function (Blueprint $table) {
            $table->dropUnique(['month', 'row_key', 'section']);
            $table->dropColumn('month');
            $table->unique(['row_key', 'section']);
        });
    }
};
