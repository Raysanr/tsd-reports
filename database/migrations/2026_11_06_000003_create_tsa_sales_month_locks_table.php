<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A closed month's final Summary Sales Report figures — see App\Models\TsaSalesMonthLock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_month_locks', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique();
            $table->longText('snapshot');
            $table->string('locked_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_sales_month_locks');
    }
};
