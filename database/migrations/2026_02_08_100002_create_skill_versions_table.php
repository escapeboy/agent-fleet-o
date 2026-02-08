<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->jsonb('input_schema')->default('{}');
            $table->jsonb('output_schema')->default('{}');
            $table->jsonb('configuration')->default('{}');
            $table->text('changelog')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['skill_id', 'version']);
            $table->index(['skill_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_versions');
    }
};
