<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** People as the "2026 MANPOWER UPDATE" sheet (explicit request,
 *  2026-10-21): birthday, employment status, shift schedule and a manual
 *  free-text status. Date of last day is the existing left_at. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('phone');
            $table->string('employment_status', 20)->nullable()->after('status');
            $table->string('shift_schedule', 20)->nullable()->after('employment_status');
            $table->string('manual_status', 160)->nullable()->after('shift_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'employment_status', 'shift_schedule', 'manual_status']);
        });
    }
};
