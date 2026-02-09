<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable()->change();
        });

        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable(false)->change();
        });

        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable(false)->change();
        });
    }
};
