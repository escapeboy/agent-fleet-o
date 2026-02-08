<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // email|telegram|slack|webhook
            $table->jsonb('target'); // recipient details
            $table->jsonb('content'); // message content
            $table->float('risk_score')->default(0); // 0.0-1.0
            $table->string('status')->default('pending_approval'); // pending_approval|approved|rejected|expired|cancelled
            $table->integer('batch_index')->default(0);
            $table->string('batch_id')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'status']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_proposals');
    }
};
