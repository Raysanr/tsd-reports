<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Summary Sales Report (explicit request, 2026-09-24:
 * "add page summary sales report in data management like this in the
 * sheets"). Replicates the source sheet's own "Summary - Sales Report" tab
 * — 3 fixed top-level GROUPS (Team Opening Shift, Team Closing Shift,
 * Tiktok Upsell), each holding a free-form list of named TSA rows an
 * admin can add/rename/remove (explicit decision, 2026-09-24 — manual
 * entry throughout, same as DSPPR - TSM Report, rather than binding to
 * real TsaShift records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_groups', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('tsa_sales_groups')->insert([
            ['label' => 'Team Opening Shift', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Team Closing Shift', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Tiktok Upsell', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_sales_groups');
    }
};
