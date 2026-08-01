<?php

use App\Models\ActivityLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * user_id alone goes NULL the moment an account is deleted (nullOnDelete(),
     * see the create-table migration's own comment — deliberate, so the log row
     * survives) — but that also silently erases WHO did the action forever, the
     * one thing an audit log exists to answer. actor_name/actor_email snapshot
     * the acting user's identity at write time instead, same way a real audit
     * log should: written once, never dependent on the users table still having
     * that row later. ActivityLogController/audit-log.blade.php prefer these
     * over the live user relation; existing rows are backfilled here from
     * whatever's still live right now, on a best-effort basis.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('actor_email')->nullable()->after('actor_name');
        });

        // Per-row (not a single UPDATE...JOIN) — SQLite's JOIN-in-UPDATE support
        // is inconsistent across driver versions; chunking is portable and this
        // table isn't large enough for that to matter.
        ActivityLog::whereNotNull('user_id')->with('user')->chunkById(500, function ($logs) {
            foreach ($logs as $log) {
                if ($log->user) {
                    $log->forceFill(['actor_name' => $log->user->name, 'actor_email' => $log->user->email])->save();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_name', 'actor_email']);
        });
    }
};
