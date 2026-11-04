<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** org_links.kind: 'child' = an extra "also under" line (parent_id → child_id);
 *  'side' = a side-to-side peer link between two cards (stored with
 *  parent_id < child_id; direction doesn't matter, never part of the tree). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_links', function (Blueprint $table) {
            $table->string('kind', 10)->default('child')->after('type');
            $table->dropUnique(['type', 'parent_id', 'child_id']);
            $table->unique(['type', 'kind', 'parent_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::table('org_links', function (Blueprint $table) {
            $table->dropUnique(['type', 'kind', 'parent_id', 'child_id']);
            $table->dropColumn('kind');
            $table->unique(['type', 'parent_id', 'child_id']);
        });
    }
};
