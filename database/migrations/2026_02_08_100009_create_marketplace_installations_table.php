<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('installed_by')->constrained('users')->cascadeOnDelete();
            $table->string('installed_version');
            $table->foreignUuid('installed_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->foreignUuid('installed_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['listing_id', 'team_id']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_installations');
    }
};
