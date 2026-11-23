<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Sign in with Google" by someone not in User Management becomes a request
 *  an admin Accepts or Rejects (2026-11-23), instead of a dead end. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('google_id')->nullable();
            $table->string('avatar', 500)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
