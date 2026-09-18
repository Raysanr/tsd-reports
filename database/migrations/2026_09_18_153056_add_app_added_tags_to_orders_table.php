<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag-loss self-healing (root-caused via /systematic-debugging, explicit
 * report 2026-09-18: "hannah just added upsell tsd tag but it is not
 * reflecting to the pos ... when reloads the page it is gone again") —
 * confirmed live against real order #1369280's own Pancake history: this
 * app's own addTag() correctly wrote "UPSELL TSD - 1 Haplunas Healing Eye
 * Cream" (editor_id matching this app's own service account, confirmed
 * against known production writes earlier this session), but ~8 minutes
 * later a DIFFERENT editor_id ("Ascano" — Hannah's own real Pancake POS
 * login, resolved via PancakeOrderTagApi::listStaff()) made a direct edit
 * inside Pancake POS itself (shipping_address + amount_owed_to_customer
 * changed in the same write) from a stale GET-then-save round trip that
 * never saw this app's tag add, silently overwriting the tags array and
 * losing it. This app cannot prevent a human editing Pancake POS directly,
 * but it CAN detect and repair the loss: app_added_tags is a durable
 * local record of every tag name this app has successfully written to an
 * order (separate from raw_tags, which just mirrors Pancake's current
 * live state and gets overwritten by the very sync that would otherwise
 * erase the evidence) — the sync job diffs the two and re-applies any
 * tracked tag Pancake no longer has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('app_added_tags')->nullable()->after('raw_tags');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('app_added_tags');
        });
    }
};
