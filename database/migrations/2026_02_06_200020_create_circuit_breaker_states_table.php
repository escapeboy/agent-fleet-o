<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuit_breaker_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state')->default('closed'); // closed|open|half_open
            $table->integer('failure_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('half_open_at')->nullable();
            $table->integer('cooldown_seconds')->default(60);
            $table->integer('failure_threshold')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_breaker_states');
    }
};
