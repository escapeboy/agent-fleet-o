<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('provider'); // anthropic|openai|google|openclaw
            $table->string('model');
            $table->string('status')->default('active'); // active|degraded|disabled|offline
            $table->jsonb('config')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->integer('cost_per_1k_input')->default(0); // credits
            $table->integer('cost_per_1k_output')->default(0); // credits
            $table->timestamp('last_health_check')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
