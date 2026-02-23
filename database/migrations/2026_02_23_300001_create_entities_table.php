<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('name');
            $table->string('canonical_name');
            $table->jsonb('metadata')->default('{}');
            $table->unsignedInteger('mention_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['team_id', 'type', 'canonical_name']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
