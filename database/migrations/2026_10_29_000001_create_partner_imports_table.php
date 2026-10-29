<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partners Data Management (App\Services\PartnerSync): which local row each
 * synced partner product/TSA became. Only filled on the partners database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_imports', function (Blueprint $table) {
            $table->id();
            $table->string('partner');
            $table->string('resource');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('local_id');
            $table->timestamps();
            $table->unique(['partner', 'resource', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_imports');
    }
};
