<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_knowledge_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('type'); // document, url, sitemap
            $table->string('name');
            $table->string('source_url', 2048)->nullable();
            $table->jsonb('source_data')->nullable(); // path, mime_type, filename, size
            $table->string('status')->default('pending'); // pending, indexing, ready, failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['chatbot_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge_sources');
    }
};
