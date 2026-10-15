<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a Pancake order did NOT become a Call Tracker lead (explicit report,
 *  2026-10-15: "there are leads that are not distributing to the tsa" —
 *  e.g. #1381321, no assignee, not in Call Tracker). One row per order,
 *  refreshed every sync tick; deleted once the order becomes a lead. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sync_skips', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->string('reason', 40);
            $table->text('detail')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('item_name')->nullable();
            $table->string('creator_name')->nullable();
            $table->dateTime('order_created_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sync_skips');
    }
};
