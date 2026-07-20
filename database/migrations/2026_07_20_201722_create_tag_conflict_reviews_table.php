<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Marks a single order's tag-vs-cart-item conflict (see
        // App\Support\TagConflicts) as looked-at, so it stops resurfacing in the
        // review queue every day once someone's checked Pancake POS and decided
        // it's fine or already fixed. Deliberately a separate table rather than a
        // column on `orders`: orders are periodically re-synced/upserted wholesale
        // from Pancake (see SyncTodayOrders), and keeping review state out of that
        // table means it can never be affected by how the sync's upsert column
        // list is written.
        Schema::create('tag_conflict_reviews', function (Blueprint $table) {
            $table->id();
            // unique() — an order has at most one "reviewed" state, not a history
            // of them; re-marking is a no-op (see TagConflictReview::markReviewed).
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_conflict_reviews');
    }
};
