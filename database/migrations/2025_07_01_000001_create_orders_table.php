<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->string('team')->nullable();
            $table->string('tsa_name')->nullable();
            $table->string('disposition')->nullable();
            $table->string('product')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->json('raw_tags')->nullable();
            $table->timestamp('pancake_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('team');
            $table->index('tsa_name');
            $table->index('pancake_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
