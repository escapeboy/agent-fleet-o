<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_response_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->uuid('team_id');
            $table->uuid('execution_id')->nullable();
            $table->integer('step_index')->default(0);
            $table->string('prompt_hash');
            $table->text('response_text');
            $table->jsonb('tools_called')->nullable();
            $table->boolean('schema_valid')->nullable();
            $table->jsonb('violations')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('agent_id');
            $table->index('team_id');
            $table->index('execution_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_response_audits');
    }
};
