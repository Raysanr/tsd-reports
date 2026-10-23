<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto-text on new lead via SMSGate (explicit request, 2026-10-23): each
 *  TSA's own phone runs the SMSGate app in Cloud mode and sends the text
 *  for leads assigned to them; each product has its own message. See
 *  App\Support\LeadAutoText. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->boolean('sms_enabled')->default(false);
            $table->string('smsgate_username')->nullable();
            $table->text('smsgate_password')->nullable(); // encrypted cast
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('sms_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn(['sms_enabled', 'smsgate_username', 'smsgate_password']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sms_template');
        });
    }
};
