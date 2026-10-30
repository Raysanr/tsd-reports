<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Removed roles (2026-10-30): any role, fixed ones included, can be removed
 *  from ONE month — soft-deleted so ensureSeeded() doesn't put it back and
 *  "Add Cost Breakdown" carries the removal forward. Earlier months keep it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
