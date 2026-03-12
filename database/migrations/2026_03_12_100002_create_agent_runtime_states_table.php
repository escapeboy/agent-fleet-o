<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runtime_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->uuid('team_id')->index();
            $table->string('session_id')->nullable();
            $table->jsonb('state_json')->nullable();
            $table->unsignedBigInteger('total_input_tokens')->default(0);
            $table->unsignedBigInteger('total_output_tokens')->default(0);
            $table->unsignedBigInteger('total_cached_tokens')->default(0);
            $table->unsignedInteger('total_cost_credits')->default(0);
            $table->unsignedInteger('total_executions')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runtime_states');
    }
};
