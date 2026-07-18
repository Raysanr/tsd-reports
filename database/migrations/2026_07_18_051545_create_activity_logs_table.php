<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only admin audit trail (who did WHAT admin action WHEN) — see
        // App\Models\ActivityLog / App\Support\ActivityLogger. user_id is nullable
        // + nullOnDelete() so a log entry survives a later deletion of the acting
        // user's own account instead of blocking that deletion via FK constraint
        // or silently cascading the history away with it.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');            // e.g. 'product.created', 'user.deactivated'
            $table->string('subject_type')->nullable();  // e.g. App\Models\Product::class, or null for bulk/non-model actions
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');       // human-readable, e.g. 'Added "Widget".'
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
