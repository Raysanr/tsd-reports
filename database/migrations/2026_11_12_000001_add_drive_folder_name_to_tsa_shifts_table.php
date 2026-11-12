<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional exact Google Drive recordings-folder name for a TSA (2026-11-12).
 *  Blank = match her folder automatically (GoogleDriveClient::resolveTsaFolder()). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->string('drive_folder_name', 100)->nullable()->after('rest_day_of_week');
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('drive_folder_name');
        });
    }
};
