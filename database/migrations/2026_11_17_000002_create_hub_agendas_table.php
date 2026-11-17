<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hub calendar agendas (explicit request, 2026-11-17): one shared
 *  calendar on the Hub, admins set agendas, everyone else only views. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_agendas', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('title', 120);
            $table->string('time', 5)->nullable();
            $table->text('note')->nullable();
            $table->string('color', 16)->default('blue');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_agendas');
    }
};
