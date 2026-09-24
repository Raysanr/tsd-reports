<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Summary Sales Report. One named TSA row within a
 * tsa_sales_group — an admin adds/renames/removes these freely (see
 * create_tsa_sales_groups_table's own doc comment for why this stays
 * manual entry rather than binding to real TsaShift records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_sales_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_sales_rows');
    }
};
