<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trigger_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('source_type')->default('*'); // '*' = any source
            $table->jsonb('conditions')->nullable();     // e.g. {"metadata.severity": {"gte": "error"}}
            $table->jsonb('input_mapping')->nullable();  // e.g. {"title": "metadata.title"}
            $table->integer('cooldown_seconds')->default(0);
            $table->integer('max_concurrent')->default(1);
            $table->enum('status', ['active', 'paused'])->default('active');
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('total_triggers')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'source_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trigger_rules');
    }
};
