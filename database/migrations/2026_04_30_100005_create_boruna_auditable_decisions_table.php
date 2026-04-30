<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boruna_auditable_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->nullableUuidMorphs('subject'); // auto-creates compound index; do not add manually
            $table->string('workflow_name');
            $table->string('workflow_version')->default('v1');
            $table->string('run_id')->unique();
            $table->string('bundle_path')->nullable();
            $table->string('status')->default('pending');
            $table->jsonb('inputs')->nullable();
            $table->jsonb('outputs')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->float('shadow_discrepancy')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status', 'created_at']);
            $table->index(['team_id', 'workflow_name']);
        });

        if (config('database.default') === 'pgsql') {
            \DB::statement('CREATE INDEX IF NOT EXISTS boruna_auditable_decisions_inputs_gin ON boruna_auditable_decisions USING gin (inputs)');
            \DB::statement('CREATE INDEX IF NOT EXISTS boruna_auditable_decisions_outputs_gin ON boruna_auditable_decisions USING gin (outputs)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boruna_auditable_decisions');
    }
};
