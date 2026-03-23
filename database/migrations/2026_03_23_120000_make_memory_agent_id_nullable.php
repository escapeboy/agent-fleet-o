<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reverse if no NULL rows exist
        Schema::table('memories', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable(false)->change();
        });
    }
};
