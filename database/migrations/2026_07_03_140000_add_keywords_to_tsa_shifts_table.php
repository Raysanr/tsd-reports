<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            // Comma-separated substrings matched (case-insensitively) against Pancake
            // order tags / seller names to attribute an order to this TSA. Moving this
            // out of the hardcoded arrays in SyncTodayOrders so new TSAs added via the
            // TSA Management page are recognized by the sync without a code change.
            $table->text('tag_keywords')->nullable()->after('team');
            $table->text('seller_keywords')->nullable()->after('tag_keywords');
        });

        // Backfill with the exact values previously hardcoded in
        // SyncTodayOrders::$tsaMap / $sellerMap, so existing sync behavior is unchanged.
        $seed = [
            'Gemma'    => ['tag' => 'GEMMA',           'seller' => 'gemma diaz'],
            'Mariel'   => ['tag' => 'MARIEL',          'seller' => 'entanto'],
            'Kathleen' => ['tag' => 'KATH,KATHLEEN',   'seller' => 'sh kathleen'],
            'Julie'    => ['tag' => 'JULIE',           'seller' => 'julie francisco'],
            'Joana'    => ['tag' => 'JOANA,JOANNA',    'seller' => 'joanamarie,caluag'],
            'Marisol'  => ['tag' => 'MARISOL',         'seller' => 'sh mari'],
        ];

        foreach ($seed as $key => $kw) {
            DB::table('tsa_shifts')->where('tsa_key', $key)->update([
                'tag_keywords'    => $kw['tag'],
                'seller_keywords' => $kw['seller'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn(['tag_keywords', 'seller_keywords']);
        });
    }
};
