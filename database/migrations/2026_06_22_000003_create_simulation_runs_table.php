<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('suite_id')->constrained('simulation_suites')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->jsonb('aggregate')->nullable();
            $table->unsignedInteger('cost_credits')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index('suite_id');
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_runs');
    }
};
