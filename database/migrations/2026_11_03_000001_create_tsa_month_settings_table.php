<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shift per month (explicit request, 2026-11-03: "when i create for this
 *  month the recent month will be not change"). One row per TSA per month
 *  holding the fields that used to rewrite every past report when edited —
 *  see App\Support\TsaMonths and App\Models\Scopes\TsaMonthScope. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_month_settings', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7);
            $table->foreignId('tsa_shift_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->string('team')->nullable();
            $table->string('shift_start')->nullable();
            $table->string('shift_end')->nullable();
            $table->string('rest_day_of_week')->nullable();
            $table->boolean('tiktok_upsell')->default(false);
            $table->timestamps();

            $table->unique(['month', 'tsa_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_month_settings');
    }
};
