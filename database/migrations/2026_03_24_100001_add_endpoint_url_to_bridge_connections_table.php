<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_connections', function (Blueprint $table) {
            // Make session_id nullable — HTTP-mode connections have no WebSocket session
            $table->string('session_id', 64)->nullable()->change();
            $table->string('endpoint_url', 500)->nullable()->after('label');
            $table->string('endpoint_secret', 255)->nullable()->after('endpoint_url');
            $table->string('tunnel_provider', 50)->nullable()->after('endpoint_secret');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_connections', function (Blueprint $table) {
            $table->dropColumn(['endpoint_url', 'endpoint_secret', 'tunnel_provider']);
            $table->string('session_id', 64)->nullable(false)->change();
        });
    }
};
