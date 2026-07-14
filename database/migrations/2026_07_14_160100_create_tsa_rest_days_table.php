<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_rest_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->date('date');
            // true = an extra day off not implied by rest_day_of_week.
            // false = an explicit override: working despite the usual rest day.
            // A row only ever exists here when the date's actual status differs from
            // what rest_day_of_week alone would produce — see TsaShift::isOffOn().
            $table->boolean('is_off');
            $table->timestamps();

            $table->unique(['tsa_shift_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_rest_days');
    }
};
