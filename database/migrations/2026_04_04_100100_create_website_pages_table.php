<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('page_type')->default('page');
            $table->string('status')->default('draft');
            $table->jsonb('grapes_json')->nullable();
            $table->longText('exported_html')->nullable();
            $table->text('exported_css')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['website_id', 'slug']);
            $table->index(['website_id', 'status']);
            $table->index(['team_id', 'page_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_pages');
    }
};
