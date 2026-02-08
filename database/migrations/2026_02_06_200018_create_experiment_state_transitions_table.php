<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiment_state_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('from_state');
            $table->string('to_state');
            $table->string('reason')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['experiment_id', 'created_at']);
            $table->index('to_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_state_transitions');
    }
};
