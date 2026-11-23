<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Locked (closed) months in Data Management (2026-11-23): a row = that
 *  month can't be edited on any Data Management page. Per dataset
 *  ("data" = TSD, "partners" = Partners Data Management). Always on the
 *  main database (App\Support\DmMonthLock). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_month_locks', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 20);
            $table->string('month', 7); // Y-m
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['dataset', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_month_locks');
    }
};
