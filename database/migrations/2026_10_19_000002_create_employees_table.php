<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TSD Archiving System people directory. Optional links to an app login
 *  (user_id) and a TSA roster row (tsa_shift_id) so a TSA's profile can
 *  show their live status/sales without duplicating that data here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tsa_shift_id')->nullable()->constrained('tsa_shifts')->nullOnDelete();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->date('hired_at')->nullable();
            $table->date('left_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
