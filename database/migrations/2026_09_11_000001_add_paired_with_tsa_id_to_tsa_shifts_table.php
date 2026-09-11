<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two TSAs sharing one physical phone (e.g. an opening-shift TSA and a
 * closing-shift TSA with no fixed schedule, per the user's explicit
 * request) can now be "paired". Only the pair's PRIMARY row (the one NOT
 * pointed to by paired_with_tsa_id) keeps a real api_token/dialer_host —
 * see TsaShift::pairWith()/unpair(). The partner's own token/host are
 * cleared while paired, since there is only one physical phone/MacroDroid
 * setup between them; CallEventController re-attributes an incoming
 * webhook to whichever of the two currently has status=calling (see
 * CallEventController::resolveTsaForToken()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->foreignId('paired_with_tsa_id')->nullable()->after('id')
                ->constrained('tsa_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paired_with_tsa_id');
        });
    }
};
