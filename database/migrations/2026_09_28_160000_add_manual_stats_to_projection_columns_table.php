<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-28: "in the
 * projection page i want to have this too" — a real template screenshot
 * showing ROAS/Standard Cost Per Message/Actual Cost Per Message alongside
 * Number of Leads/Conversion Rate/Number of Orders/Average Order Value, the
 * same top block Expected Income already has). Confirmed this does NOT
 * change how Projections calculates anything — it stays target-driven (Net
 * Income Target back-solved into Orders/Leads Needed, see
 * ProjectionCalculator::targetCard()); Number of Leads/Conversion Rate/
 * Number of Orders/Average Order Value in this new block just DISPLAY the
 * existing leads_needed/conversion_rate/orders/average_order_value figures
 * rather than being new inputs. ROAS/Standard Cost Per Message/Actual Cost
 * Per Message are the only 3 genuinely new fields — plain manual inputs
 * with no formula participation, same as Expected Income's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->decimal('roas', 10, 2)->default(0)->after('label');
            $table->decimal('standard_cost_per_message', 10, 2)->default(0)->after('roas');
            $table->decimal('actual_cost_per_lead', 10, 2)->default(0)->after('standard_cost_per_message');
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropColumn(['roas', 'standard_cost_per_message', 'actual_cost_per_lead']);
        });
    }
};
