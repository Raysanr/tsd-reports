<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links an Archiving System person to a synced partner TSA
 *  ("PEAK.CO:JOANA" — tsa_shifts.tsa_key on the partners database), the
 *  partner counterpart of tsa_shift_id. See ArchivePartnerSync. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('partner_tsa_key', 120)->nullable()->unique()->after('tsa_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['partner_tsa_key']);
            $table->dropColumn('partner_tsa_key');
        });
    }
};
