<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('email_theme_id')->nullable()->constrained('email_themes')->nullOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preview_text', 200)->nullable();
            $table->jsonb('design_json')->default('{}');
            $table->longText('html_cache')->nullable();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('private');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
