<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_triage_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('source_kind', 16);                  // approval | proposal | human_task
            $table->uuid('source_id');                          // approval_request.id or outbound_proposal.id
            $table->decimal('llm_score', 3, 2);
            $table->string('llm_recommendation', 32);           // review_now | review_soon | low_priority
            $table->text('llm_reason');
            $table->string('user_action', 32)->nullable();      // approved | rejected | null
            $table->timestamp('user_acted_at')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['team_id', 'source_kind', 'source_id']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_triage_results');
    }
};
