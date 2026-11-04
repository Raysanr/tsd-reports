<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archiving System — disciplinary records: NTEs, warnings, suspensions,
 *  with the TSA's reply and the final decision. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->date('incident_date')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('reply_due_on')->nullable();
            $table->text('reply')->nullable();
            $table->date('replied_on')->nullable();
            $table->text('decision')->nullable();
            $table->string('status', 20)->default('open')->index(); // open | replied | resolved
            $table->date('resolved_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
