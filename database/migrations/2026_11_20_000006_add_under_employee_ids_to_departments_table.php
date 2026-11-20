<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** People a team is drawn under on the Org Chart instead of its parent
 *  team's head — several people = one shared group (2026-11-20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('under_employee_ids')->nullable()->after('head_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('under_employee_ids');
        });
    }
};
