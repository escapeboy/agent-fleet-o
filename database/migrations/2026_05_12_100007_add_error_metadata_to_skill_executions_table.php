<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('error_message');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS skill_executions_sentry_event_id_idx '
                ."ON skill_executions ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS skill_executions_sentry_event_id_idx');
        }

        Schema::table('skill_executions', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
