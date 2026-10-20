<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops the 15-minute reconcile from reverting staff edits (2026-10-20,
 * root-caused from live Pancake history: #1380806's corrected barangay put
 * back one minute later by "API (For TSD)"; staff internal notes deleted on
 * #1380173/#1380141). The restore code is gone; this clears every order's
 * remembered note/address so nothing already guarded stays guarded.
 * app_added_tags (tags are only ever merged in, never overwritten) is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where(fn ($q) => $q->whereNotNull('app_note')->orWhereNotNull('app_note_print')->orWhereNotNull('app_shipping_address'))
            ->update(['app_note' => null, 'app_note_print' => null, 'app_shipping_address' => null]);
    }

    public function down(): void
    {
        // Not reversible — the cleared values were stale copies by definition.
    }
};
