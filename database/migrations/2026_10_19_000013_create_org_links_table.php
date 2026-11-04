<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extra Org Chart connections beyond a card's main parent/manager
 *  (departments.parent_id / employees.manager_id) — "also reports to". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_links', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // department | employee
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('child_id');
            $table->timestamps();
            $table->unique(['type', 'parent_id', 'child_id']);
            $table->index(['type', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_links');
    }
};
