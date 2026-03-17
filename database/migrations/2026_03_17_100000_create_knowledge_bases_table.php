<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('agent_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('idle'); // idle, ingesting, ready, error
            $table->integer('chunks_count')->default(0);
            $table->timestamp('last_ingested_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();

            $table->index(['team_id', 'deleted_at']);
            $table->index(['agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
