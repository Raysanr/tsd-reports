<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Cache of
 * page_access_token per Facebook page_id (a Pancake shop can have leads
 * coming in through more than one connected page). Generated on demand from
 * the personal pancake_access_token via POST /pages/{page_id}/
 * generate_page_access_token — this token itself does not expire, so once
 * generated it doesn't need the user's 90-day token again until this cache
 * is cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pancake_page_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->unique();
            $table->text('page_access_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pancake_page_tokens');
    }
};
