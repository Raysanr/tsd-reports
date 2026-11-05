<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** # of TSA on TSD Overall Performance's Sales Summary, typed per department per day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overall_tsa_counts', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('department');
            $table->unsignedSmallInteger('working');
            $table->unsignedSmallInteger('total');
            $table->timestamps();
            $table->unique(['entry_date', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overall_tsa_counts');
    }
};
