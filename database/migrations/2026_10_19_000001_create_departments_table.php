<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TSD Archiving System (explicit request, 2026-10-19) — the company's own
 *  department tree. parent_id is self-referencing (Company → Department →
 *  Team); head_employee_id has no DB-level FK on purpose (employees is
 *  created after this table and SQLite can't add an FK later) — cleared in
 *  code when that employee is deleted, see Employee::booted(). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('sky');
            $table->unsignedBigInteger('head_employee_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
