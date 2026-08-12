<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Distinct from
 * `orders` — a Lead is a workflow-state row for the assignment/calling
 * pipeline (fed by SyncPancakeLeads), while `orders` is the completed-sale
 * reporting record (fed by a separate sync). Both read the same underlying
 * Pancake data at different lifecycle stages; kept as separate tables rather
 * than merged, since unifying them would conflate two different concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->string('customer_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('conversation_link')->nullable();
            $table->string('pancake_page_id')->nullable();
            $table->string('pancake_conversation_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // References tsa_shifts, not a `tsas` table — TsaShift is the
            // single TSA-roster concept in the merged app (see Phase 4's
            // add_call_tracker_columns_to_tsa_shifts_table migration).
            $table->foreignId('tsa_id')->nullable()->constrained('tsa_shifts')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            // 'unassigned' — no product match, or that product's queue is
            // empty; sits visible so an admin can fix the product/roster
            // config rather than the lead silently vanishing.
            // 'assigned'   — round-robin has picked a TSA, not yet called.
            // 'called'     — the assigned TSA logged an outcome (see
            // disposition/called_at).
            $table->string('status')->default('unassigned');
            $table->string('disposition')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->foreignId('called_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pancake_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('callback_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
