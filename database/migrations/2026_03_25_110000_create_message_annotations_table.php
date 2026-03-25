<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('assistant_messages')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // 'positive' or 'negative'
            $table->string('rating', 20);
            // Optional corrected response to use instead
            $table->text('correction')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['team_id', 'created_at']);
            // One annotation per user per message
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_annotations');
    }
};
