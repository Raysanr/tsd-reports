<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge phase 4 (2026-08-12): extends the existing `tsa_shifts` table
 * (already the canonical TSA-roster concept, joined on `tsa_key` against
 * `orders.tsa_name` for reporting) with the columns call-tracker's own
 * `tsas` table had — rather than keeping two separate roster tables. Purely
 * additive: none of TsaShift's existing columns/behavior (isOffOn, keyword
 * matching, used by live reporting sync) are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('display_name');
            $table->string('dialer_host')->nullable();
            $table->string('api_token')->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->string('status')->default('login');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_locked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_locked_by');
            $table->dropColumn(['phone_number', 'dialer_host', 'api_token', 'active', 'status', 'status_changed_at']);
        });
    }
};
