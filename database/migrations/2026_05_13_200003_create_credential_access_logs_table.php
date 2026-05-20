<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->constrained('credentials')->cascadeOnDelete();
            $table->uuid('team_id');
            $table->uuid('agent_id')->nullable();
            $table->uuid('tool_id')->nullable();
            $table->string('resolved_for');
            $table->string('target_domain')->nullable();
            $table->boolean('allowed')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index('credential_id');
            $table->index('team_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_access_logs');
    }
};
