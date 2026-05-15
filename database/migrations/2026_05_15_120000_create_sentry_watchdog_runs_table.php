<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentry_watchdog_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->integer('signals_triaged')->default(0);
            $table->integer('prs_opened')->default(0);
            $table->integer('investigate_only')->default(0);
            $table->integer('critical_count')->default(0);
            $table->text('digest_summary')->nullable();

            $table->index('integration_id');
            $table->index('team_id');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentry_watchdog_runs');
    }
};
