<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('status');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS ai_runs_sentry_event_id_idx '
                ."ON ai_runs ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ai_runs_sentry_event_id_idx');
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
