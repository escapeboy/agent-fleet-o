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
            $table->foreignUuid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('slug', 255);
            $table->string('title', 255);
            $table->string('page_type')->default('page');
            $table->string('status')->default('draft');
            $table->jsonb('grapes_json')->nullable();
            $table->text('exported_html')->nullable();
            $table->text('exported_css')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_pages');
    }
};
