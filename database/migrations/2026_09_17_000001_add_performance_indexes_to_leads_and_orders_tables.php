<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance fix (explicit request, 2026-09-17: "why is it so slow") —
 * every Leads/Overdue/Callbacks view, the sidebar notification badge, and
 * Monitor's per-TSA health cards filter/sort on assigned_at and
 * pancake_created_at (LeadController::index(), NotificationController::
 * counts(), MonitorController::index()), and Order's own status filter
 * queries status_code — none of these had an index (confirmed live via
 * Schema::getIndexes(), 2026-09-17: leads had only status/callback_at/its
 * foreign keys, orders had none on status_code), so every one of those
 * queries was a full table scan.
 *
 * leads gets a composite (status, assigned_at) index — every Overdue-shape
 * query filters both together (status='assigned' AND assigned_at <=
 * threshold), so one composite index serves that whole query shape better
 * than two separate single-column ones would — plus a standalone
 * pancake_created_at index, since that column is filtered nearly everywhere
 * (Leads/Overdue/Callbacks/notification badges) independently of status.
 *
 * orders gets a plain status_code index — Order::where('status_code', ...)
 * (the order-status filter on the Leads table) had nothing to use at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['status', 'assigned_at']);
            $table->index('pancake_created_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['status', 'assigned_at']);
            $table->dropIndex(['pancake_created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status_code']);
        });
    }
};
