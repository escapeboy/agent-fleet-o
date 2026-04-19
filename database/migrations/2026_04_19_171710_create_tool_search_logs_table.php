<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_search_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('experiment_id')->nullable();
            $table->text('query');
            $table->integer('pool_size')->default(0);
            $table->integer('matched_count')->default(0);
            $table->jsonb('matched_slugs')->default('[]');
            $table->jsonb('matched_ids')->default('[]');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_search_logs');
    }
};
