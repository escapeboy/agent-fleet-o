<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentry_fix_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->foreignUuid('signal_id')->nullable()->constrained('signals')->nullOnDelete();
            $table->string('sentry_issue_id');
            $table->string('project')->nullable();
            $table->string('repository');
            $table->string('title', 500);
            $table->string('culprit', 500)->nullable();
            $table->string('sentry_url', 1000)->nullable();
            $table->text('root_cause')->nullable();
            $table->json('suspect_files')->nullable();
            $table->float('confidence')->default(0);
            $table->boolean('is_critical')->default(false);
            $table->string('tier', 8)->nullable();
            $table->string('state', 32)->default('queued');
            $table->string('claimed_by')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('pr_url', 1000)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('events')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'state', 'created_at']);
            $table->index(['state', 'lease_expires_at']);
        });

        // At most one live task per Sentry issue and integration. Partial unique
        // indexes are supported by both PostgreSQL and SQLite (the test driver).
        DB::statement(
            'CREATE UNIQUE INDEX sentry_fix_tasks_live_issue_unique ON sentry_fix_tasks (integration_id, sentry_issue_id) '
            ."WHERE state NOT IN ('done', 'reverted', 'rejected', 'failed', 'escalated')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sentry_fix_tasks');
    }
};
