<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links an Archiving System team (departments row) to a config('teams')
 *  slug, so the TSA Management roster sync knows which team each TSA
 *  lands in. Null for teams that aren't a selling team (QA, Admin…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('team_slug', 40)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['team_slug']);
            $table->dropColumn('team_slug');
        });
    }
};
