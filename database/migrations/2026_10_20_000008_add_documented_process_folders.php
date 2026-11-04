<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TSD Documented Process (formerly Script Library): every page and every
 *  uploaded file lives in one of the Drive-matching folders
 *  (App\Support\DocumentedProcess::FOLDERS). Existing pages are filed by
 *  their old type. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_items', function (Blueprint $table) {
            $table->string('folder', 40)->nullable()->index()->after('kind');
        });
        Schema::table('archive_documents', function (Blueprint $table) {
            $table->string('process_folder', 40)->nullable()->index()->after('category');
        });

        foreach (['script' => 'tsd-call-flow', 'rebuttal' => 'tsd-call-flow', 'product' => 'product-overview', 'faq' => 'training-process'] as $kind => $folder) {
            DB::table('library_items')->where('kind', $kind)->whereNull('folder')->update(['folder' => $folder]);
        }
        DB::table('library_items')->whereNull('folder')->update(['folder' => 'training-process']);
    }

    public function down(): void
    {
        Schema::table('library_items', fn (Blueprint $t) => $t->dropColumn('folder'));
        Schema::table('archive_documents', fn (Blueprint $t) => $t->dropColumn('process_folder'));
    }
};
