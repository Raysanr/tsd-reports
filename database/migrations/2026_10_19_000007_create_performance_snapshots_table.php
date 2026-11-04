<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archiving System — each TSA's numbers for one month, frozen so their
 *  history survives later re-syncs/re-tags of the underlying orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->date('month'); // first day of the month
            $table->string('tsa_name');
            $table->string('team')->nullable();
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('called')->default(0);
            $table->unsignedInteger('upsells')->default(0);
            $table->decimal('upsell_sales', 12, 2)->default(0);
            $table->decimal('conversion_rate', 5, 1)->nullable();
            $table->decimal('delivered', 12, 2)->default(0);
            $table->decimal('rts', 12, 2)->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tsa_shift_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_snapshots');
    }
};
