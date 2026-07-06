<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('match_keyword')->nullable(); // null = match on display_name itself
            $table->string('team'); // literal order_team string, e.g. "SH Naturals"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed from config/teams.php's contents as of 2026-07-06 (the original 14
        // products plus VisionEx/Vision Pro, added earlier the same day) so behavior
        // is identical the moment this ships.
        $seed = [
            // SH Naturals
            ['display_name' => 'SINUXYL',             'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'SINUVEX',             'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'STEAMPACK',           'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'AUDICURE',            'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'GINSENG SERUM',       'match_keyword' => 'GINSENG',  'team' => 'SH Naturals'],
            ['display_name' => 'VITAMIN C TONER',     'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'CANPRO JUICE DRINK',  'match_keyword' => 'CANPRO',   'team' => 'SH Naturals'],
            ['display_name' => 'BATH PACK',           'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'SCAR CREAM',          'match_keyword' => null,       'team' => 'SH Naturals'],
            ['display_name' => 'MINI GB',             'match_keyword' => null,       'team' => 'SH Naturals'],
            // Eyecare
            ['display_name' => 'CLEARSIGHT',          'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'PTERYGIUM',           'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'GLAUCO FREE',         'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'LUMIEYES',            'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'VISIONEX',            'match_keyword' => null,       'team' => 'Eyecare Team'],
            ['display_name' => 'VISION PRO',          'match_keyword' => null,       'team' => 'Eyecare Team'],
        ];

        foreach ($seed as $i => $row) {
            DB::table('products')->insert(array_merge($row, [
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
