<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archiving System — a person's lifecycle milestones (hired, team/manager/
 *  position/status changes recorded automatically, plus manual ones). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->date('occurred_on')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_events');
    }
};
