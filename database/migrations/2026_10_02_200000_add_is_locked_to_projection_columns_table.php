<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-10-02: "add
 * lock icon on this in the right side ... when it is lock it can't
 * edit") — Opening Shift/Closing Shift are the only 2 cards with any real
 * editable inputs (every other card's own P&L is a pure formula copied
 * from one of these two, already always read-only — see
 * _column.blade.php's own $editable). Locking freezes every one of a
 * locked card's own fields as read-only, same as a derived card already
 * renders, without touching its stored values — toggled from the card's
 * own header, persisted so it stays locked for every admin until
 * explicitly unlocked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
