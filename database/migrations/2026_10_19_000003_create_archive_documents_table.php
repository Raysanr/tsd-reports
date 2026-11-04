<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TSD Archiving System file store. Files themselves live on the dedicated
 *  'archive' disk (config/filesystems.php) — this table is metadata only.
 *  Soft-deleted rows are the module's Trash; the file is only removed from
 *  disk on a permanent delete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category', 40)->index();
            $table->text('description')->nullable();
            $table->string('tags')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->date('expires_at')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_documents');
    }
};
