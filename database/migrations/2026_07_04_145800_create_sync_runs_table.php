<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->unsignedInteger('total_synced')->default(0);
            $table->unsignedInteger('new_orders')->default(0);
            $table->unsignedInteger('upsell_count')->default(0);
            $table->decimal('upsell_sales', 10, 2)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(true);
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index('ran_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
