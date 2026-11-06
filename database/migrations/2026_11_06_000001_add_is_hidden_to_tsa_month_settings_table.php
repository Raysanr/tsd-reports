<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The report eye becomes per month (explicit request, 2026-11-06). Every
 *  saved month starts with each TSA's current eye, so nothing moves today. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_month_settings', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('tiktok_upsell');
        });

        $hiddenIds = DB::table('tsa_shifts')->where('is_hidden', true)->pluck('id');
        DB::table('tsa_month_settings')->whereIn('tsa_shift_id', $hiddenIds)->update(['is_hidden' => true]);
    }

    public function down(): void
    {
        Schema::table('tsa_month_settings', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
