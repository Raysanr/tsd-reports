<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            // Links this TSA to the real Pancake POS account picked in the
            // "Add/Edit TSA" search dropdown, so seller_keywords can be
            // derived from an actual account name instead of a guess.
            $table->string('pos_user_id')->nullable()->after('tsa_key');
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('pos_user_id');
        });
    }
};
