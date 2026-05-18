<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_file_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('sandbox_id')->nullable();
            $table->string('path');
            $table->string('operation')->default('created');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['experiment_id', 'captured_at']);
            $table->index(['team_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_file_activities');
    }
};
