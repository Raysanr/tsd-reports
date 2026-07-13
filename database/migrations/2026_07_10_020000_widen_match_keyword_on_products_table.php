<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // match_keyword now holds a comma-separated alias list ("PTERYGIUM,
            // PteryFix, Ptery Fix"), not a single keyword — the default 255 gets
            // tight once a product accumulates a few cart-name variants.
            $table->string('match_keyword', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('match_keyword', 255)->nullable()->change();
        });
    }
};
