<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TSD Scoreboard — months whose Total Sales / Net Income come from the
 *  team's own Google Sheet instead of the system (Jan–Apr 2026, before the
 *  app had the data). One row per TSA per month; tsa_shift_id is null for a
 *  former TSA who isn't on the app's roster at all. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoreboard_imports', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->string('name');
            $table->string('team_slug', 40)->nullable();
            $table->foreignId('tsa_shift_id')->nullable()->constrained('tsa_shifts')->nullOnDelete();
            $table->decimal('sales', 18, 6)->default(0);
            $table->decimal('net', 18, 6)->default(0);
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['month', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoreboard_imports');
    }
};
