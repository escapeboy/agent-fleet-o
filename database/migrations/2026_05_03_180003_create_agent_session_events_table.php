<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_session_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('session_id');
            $table->bigInteger('seq');
            $table->string('kind', 32);
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at');

            $table->unique(['session_id', 'seq']);
            $table->index(['session_id', 'created_at']);
            $table->index(['team_id', 'kind']);

            $table->foreign('session_id')
                ->references('id')->on('agent_sessions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_session_events');
    }
};
