<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Files attached to an Incident / NTE (explicit request, 2026-10-22).
 *  Stored as archive documents so preview, download and trash all work. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_documents', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('process_subfolder_id')->constrained('incidents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('archive_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });
    }
};
