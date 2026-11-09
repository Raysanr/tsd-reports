<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual Cost Breakdown (2026-11-09): the same page on its own rows. Every
 * existing row is the original ("budget") Cost Breakdown; the unique keys
 * gain `variant` so an actual row can share a month/key with a budget one.
 * See App\Support\CostBreakdownVariant.
 */
return new class extends Migration
{
    /** table => [old unique columns, new unique columns] */
    private const TABLES = [
        'cost_breakdown_roles' => [['seed_key', 'month'], ['seed_key', 'month', 'variant']],
        'cost_breakdown_pools' => [['key', 'month'], ['key', 'month', 'variant']],
        'cost_breakdown_tsa_entries' => [['tsa_id', 'month'], ['tsa_id', 'month', 'variant']],
        'cost_breakdown_product_flags' => [['month', 'product_id'], ['month', 'product_id', 'variant']],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$old, $new]) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('variant', 10)->default('budget')->after('month');
            });
            Schema::table($table, function (Blueprint $t) use ($old, $new) {
                // New index first: on MySQL the old one may back a foreign key.
                $t->unique($new);
                $t->dropUnique($old);
                $t->index('variant');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$old, $new]) {
            \Illuminate\Support\Facades\DB::table($table)->where('variant', '!=', 'budget')->delete();
            Schema::table($table, function (Blueprint $t) use ($old, $new) {
                $t->unique($old);
                $t->dropUnique($new);
                $t->dropIndex(['variant']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('variant');
            });
        }
    }
};
