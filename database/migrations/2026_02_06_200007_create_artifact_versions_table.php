<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('artifact_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->foreignUuid('created_by_ai_run')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_versions');
    }
};
