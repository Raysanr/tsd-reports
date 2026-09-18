<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct messaging between User accounts (explicit request, 2026-09-18: "is
 * it possible that can have a feature that can message the tsa" — confirmed
 * scope: two-way, any user can message any other user, TSD Reports side
 * tied to User accounts, not Call Tracker's separate TsaShift roster).
 *
 * A flat messages table, not a separate conversations table — a
 * "conversation" between two users is just every Message row where
 * {sender_id, recipient_id} matches that pair in either direction; grouping
 * by the OTHER party is done in the query layer (MessageController), not a
 * separate persisted concept. Simpler schema, no conversation-lifecycle
 * bookkeeping (create/find-or-create a conversation row) needed for a
 * plain 1:1 DM model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Every conversation-thread query filters by "the two users
            // involved, newest first" — this composite index serves both
            // directions of a 1:1 thread (sender->recipient and the
            // reverse) when the query's own whereIn/orWhere covers both,
            // same as the unread-count query's own recipient_id + read_at
            // lookup.
            $table->index(['sender_id', 'recipient_id', 'created_at']);
            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
