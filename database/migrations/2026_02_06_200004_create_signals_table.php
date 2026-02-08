<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type'); // rss|webhook|scrape|email|manual
            $table->string('source_identifier');
            $table->jsonb('payload');
            $table->float('score')->nullable(); // 0.0-1.0
            $table->jsonb('scoring_details')->nullable();
            $table->string('content_hash', 64); // SHA-256 for dedup
            $table->jsonb('tags')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->unique('content_hash');
            $table->index('source_type');
            $table->index('experiment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
