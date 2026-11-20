<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which MacroDroid trigger sent a phone report ("Call Ended", "Call
 *  Outgoing"…) — see CallEventController::isEndReport(). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_events', function (Blueprint $table) {
            $table->string('trigger', 60)->nullable()->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('call_events', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });
    }
};
