<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image attachments for direct messages (explicit request, 2026-09-18: "is
 * it possible that can send a picture too?"). Stored as base64 IN the row
 * (longText), not on local disk — the tsd-reports web service has no
 * attached Railway volume (confirmed via `railway volume list`, only the
 * Postgres service has one), so anything written to local disk is wiped on
 * every redeploy. A DB-stored blob sidesteps that entirely with zero infra
 * changes, which fits this feature's traffic profile (occasional images in
 * an internal team chat, not a media-heavy app) — see
 * MessageController::send()'s own doc comment for the size cap this trades
 * off against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('image')->nullable()->after('body');
            $table->string('image_mime')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['image', 'image_mime']);
        });
    }
};
