<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok Upsell roster flag (explicit request, 2026-10-05: a manually-run
 * "TikTok Upsell" section on the Summary Sales Report, separate from the
 * real SH Naturals/Eyecare teams). A plain independent boolean, NOT a
 * third `team` value — `tsa_shifts.team`/`orders.team` are tightly coupled
 * to the real Pancake-synced team system (see config/teams.php and
 * TsaManagementController::validateTsa()'s own `in:` validation against
 * Teams::config()), so a fake team string there would risk colliding with
 * real order-team matching. This flag just marks which TSAs show up in
 * the TikTok Upsell section, independent of their real team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->boolean('tiktok_upsell')->default(false)->after('team');
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('tiktok_upsell');
        });
    }
};
