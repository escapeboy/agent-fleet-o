<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('type'); // purchase|deduction|refund|reservation|release
            $table->integer('amount'); // positive=credit, negative=debit
            $table->integer('balance_after');
            $table->string('description');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['experiment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledgers');
    }
};
