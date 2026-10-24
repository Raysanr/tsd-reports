<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Breakdown's "Cost Allocation Per TSA" product checkboxes become
 * per month (explicit request, 2026-10-24). Every existing Cost Breakdown
 * month gets a copy of today's checkboxes, so nothing changes on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_breakdown_product_flags', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7);
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('has_cost_allocation')->default(false);
            $table->timestamps();
            $table->unique(['month', 'product_id']);
        });

        $months = DB::table('cost_breakdown_pools')->distinct()->pluck('month');
        $products = DB::table('products')->get(['id', 'has_cost_allocation']);
        $now = now();
        foreach ($months as $month) {
            DB::table('cost_breakdown_product_flags')->insert($products->map(fn ($p) => [
                'month' => $month, 'product_id' => $p->id, 'has_cost_allocation' => (bool) $p->has_cost_allocation,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_breakdown_product_flags');
    }
};
