<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_provider_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // openai, anthropic, google, etc.
            $table->text('credentials'); // encrypted JSON: {api_key: "...", org_id: "..."}
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'provider']);
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_provider_credentials');
    }
};
