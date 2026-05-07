<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->string('signature_header', 128)->nullable();
            $table->string('signature_format', 64)->nullable();
            $table->string('signature_algo', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['signature_header', 'signature_format', 'signature_algo']);
        });
    }
};
