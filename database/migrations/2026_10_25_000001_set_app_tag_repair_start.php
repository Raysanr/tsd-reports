<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The queued-tag repair (ReconcileOrderStatuses::reconcileVanishedAppTags)
 *  only touches orders that come in from this deploy onward (explicit
 *  request, 2026-10-25: "fix only onward … so it will be not complicate
 *  past orders"). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'app_tag_repair_from'],
            ['value' => now()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'app_tag_repair_from')->delete();
    }
};
