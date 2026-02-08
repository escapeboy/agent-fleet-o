<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('published_by')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // skill or agent
            $table->foreignUuid('listable_id'); // references skills.id or agents.id
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->text('readme')->nullable();
            $table->string('category')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('status')->default('draft');
            $table->string('visibility')->default('public');
            $table->string('version');
            $table->jsonb('configuration_snapshot')->default('{}');
            $table->unsignedInteger('install_count')->default(0);
            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'visibility']);
            $table->index(['type', 'category']);
            $table->index(['install_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }
};
