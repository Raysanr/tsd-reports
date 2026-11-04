<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archiving System — coaching sessions and QA scorecards per person. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaching_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20)->index(); // coaching | qa
            $table->date('session_date')->index();
            $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('topic', 160);
            $table->string('reference', 120)->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('action_plan')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_records');
    }
};
