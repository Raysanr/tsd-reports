<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archiving System — call scripts, rebuttals and product sheets, with every
 *  edit kept as a version. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index(); // script | rebuttal | product | faq
            $table->string('title', 200);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('library_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_item_id')->constrained('library_items')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('body');
            $table->string('change_note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['library_item_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_versions');
        Schema::dropIfExists('library_items');
    }
};
