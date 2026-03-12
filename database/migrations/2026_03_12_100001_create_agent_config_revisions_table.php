<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_config_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->uuid('team_id')->index();
            $table->uuid('created_by')->nullable();
            $table->jsonb('before_config');
            $table->jsonb('after_config');
            $table->jsonb('changed_keys');
            $table->string('source')->default('manual'); // manual|api|evolution|rollback
            $table->uuid('rolled_back_from_revision_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_config_revisions');
    }
};
