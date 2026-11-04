<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where each card sits on the Org Chart canvas (Teams view uses
 *  departments, People view uses employees). Null = auto-layout. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'employees'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->integer('chart_x')->nullable();
                $table->integer('chart_y')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['departments', 'employees'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['chart_x', 'chart_y']);
            });
        }
    }
};
