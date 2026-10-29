<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_assignee_orders', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->string('customer_name')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('pancake_created_at')->nullable();
            $table->dateTime('release_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_assignee_orders');
    }
};
