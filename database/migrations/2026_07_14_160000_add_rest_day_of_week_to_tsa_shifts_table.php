<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            // Lowercase full day name ("sunday".."saturday"), or null = no recurring
            // rest day. One-off exceptions (extra days off, or working through the
            // usual rest day) live in tsa_rest_days instead — see that table's comment.
            $table->string('rest_day_of_week')->nullable()->after('seller_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('rest_day_of_week');
        });
    }
};
