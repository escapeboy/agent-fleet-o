<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignUuid('installation_id')->constrained('marketplace_installations')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->decimal('cost_credits', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['listing_id', 'executed_at']);
            $table->index(['installation_id', 'executed_at']);
            $table->index(['team_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_usage_records');
    }
};
