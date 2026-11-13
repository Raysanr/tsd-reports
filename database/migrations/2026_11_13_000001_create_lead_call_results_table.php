<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Exact call results per lead, from Pancake's tag history (2026-11-13) —
 *  see App\Support\LeadCallResults. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_call_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('result', 100);
            $table->foreignId('tsa_id')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['lead_id', 'result', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('result_tags_signature', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_call_results');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('result_tags_signature');
        });
    }
};
