<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('tsa_key')->unique();     // must match orders.tsa_name exactly
            $table->string('display_name');           // "Gemma De Guzman"
            $table->string('team')->nullable();
            $table->string('shift_start')->nullable(); // "08:01"
            $table->string('shift_end')->nullable();   // "09:00"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed known TSAs
        $seed = [
            ['tsa_key' => 'Gemma',    'display_name' => 'Gemma De Guzman',     'team' => 'SH Naturals',  'sort_order' => 0],
            ['tsa_key' => 'Mariel',   'display_name' => 'Mariel Entanto',       'team' => 'SH Naturals',  'sort_order' => 1],
            ['tsa_key' => 'Kathleen', 'display_name' => 'Kathleen Santilleses', 'team' => 'SH Naturals',  'sort_order' => 2],
            ['tsa_key' => 'Julie',    'display_name' => 'Julie',                'team' => 'Eyecare Team', 'sort_order' => 3],
            ['tsa_key' => 'Joana',    'display_name' => 'Joana',                'team' => 'Eyecare Team', 'sort_order' => 4],
            ['tsa_key' => 'Marisol',  'display_name' => 'Marisol',              'team' => 'Eyecare Team', 'sort_order' => 5],
        ];

        foreach ($seed as $row) {
            DB::table('tsa_shifts')->insert(array_merge($row, [
                'shift_start' => null,
                'shift_end'   => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_shifts');
    }
};
