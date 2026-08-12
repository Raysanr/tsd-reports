<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Per-lead call
 * timeline (created/assigned/called/callback scheduled) — distinct from
 * `activity_logs`, which is the admin-facing audit trail of config/
 * management actions across the whole app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // created, assigned, called, callback_scheduled, upsell_added
            $table->string('description');
            // Structured peso value for an 'upsell_added' activity — the
            // description already carries a human-readable "₱499.00 × 2" for
            // the feed, but summing "today's upsells" needs a real number.
            // Null for every other activity type.
            $table->decimal('amount', 10, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
