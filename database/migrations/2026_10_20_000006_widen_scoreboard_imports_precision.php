<?php

use App\Support\ScoreboardSheetImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keep the sheet's full precision (explicit request, 2026-10-20): with
 *  values rounded to 2 decimals per TSA, monthly Net Income totals drifted
 *  1–2 centavos from the sheet's own totals (the sheet sums unrounded
 *  values). 6 decimals makes every total match; the UI still shows 2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoreboard_imports', function (Blueprint $table) {
            $table->decimal('sales', 18, 6)->default(0)->change();
            $table->decimal('net', 18, 6)->default(0)->change();
        });
        ScoreboardSheetImport::run(); // reload with the full-precision values
    }

    public function down(): void
    {
        Schema::table('scoreboard_imports', function (Blueprint $table) {
            $table->decimal('sales', 14, 2)->default(0)->change();
            $table->decimal('net', 14, 2)->default(0)->change();
        });
    }
};
