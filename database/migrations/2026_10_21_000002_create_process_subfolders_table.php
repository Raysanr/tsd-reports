<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Subfolders inside the TSD Documented Process folders (explicit request,
 *  2026-10-21: "can create folder in the folders like sub folder"). Any
 *  depth: a subfolder's parent is another subfolder, or null for the top
 *  level of its Documented Process folder. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_subfolders', function (Blueprint $table) {
            $table->id();
            $table->string('folder', 40); // DocumentedProcess::FOLDERS slug
            $table->foreignId('parent_id')->nullable()->constrained('process_subfolders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['folder', 'parent_id']);
        });

        Schema::table('archive_documents', function (Blueprint $table) {
            $table->foreignId('process_subfolder_id')->nullable()->after('process_folder')->constrained('process_subfolders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('archive_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('process_subfolder_id');
        });
        Schema::dropIfExists('process_subfolders');
    }
};
