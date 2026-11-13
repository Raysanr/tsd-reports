<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** No Confirmation page (2026-11-13) — see App\Support\UpsellConfirmation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('upsell_at')->nullable();
            // null = not checked yet, confirmed, unconfirmed, unreadable
            $table->string('upsell_confirmation', 20)->nullable()->index();
            $table->string('upsell_confirmation_text', 255)->nullable();
            $table->timestamp('upsell_confirmation_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['upsell_confirmation']);
            $table->dropColumn(['upsell_at', 'upsell_confirmation', 'upsell_confirmation_text', 'upsell_confirmation_checked_at']);
        });
    }
};
