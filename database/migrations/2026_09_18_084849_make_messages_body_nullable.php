<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An image-only message has no body text — see the sibling
 * add_image_to_messages_table migration. Raw DDL instead of
 * Schema::table(...)->nullable()->change() to avoid adding doctrine/dbal
 * (not installed in this project) just for a one-column nullability
 * change; works identically on both drivers this app runs on (SQLite/MySQL
 * locally, Postgres in production).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages ALTER COLUMN body DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE messages MODIFY body TEXT NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite DOES enforce NOT NULL (confirmed: local test suite
            // failed inserting an image-only row without this) and has no
            // ALTER COLUMN — dropping/re-adding the column is the standard
            // workaround for a single nullable-constraint change here.
            DB::statement('ALTER TABLE messages DROP COLUMN body');
            DB::statement('ALTER TABLE messages ADD COLUMN body TEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::statement("UPDATE messages SET body = '' WHERE body IS NULL");

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages ALTER COLUMN body SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE messages MODIFY body TEXT NOT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('ALTER TABLE messages DROP COLUMN body');
            DB::statement('ALTER TABLE messages ADD COLUMN body TEXT NOT NULL DEFAULT \'\'');
        }
    }
};
