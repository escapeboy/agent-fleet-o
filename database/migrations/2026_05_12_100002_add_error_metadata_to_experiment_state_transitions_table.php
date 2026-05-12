<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_state_transitions', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('metadata');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \DB::statement(
                'CREATE INDEX IF NOT EXISTS experiment_state_transitions_sentry_event_id_idx '
                ."ON experiment_state_transitions ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \DB::statement('DROP INDEX IF EXISTS experiment_state_transitions_sentry_event_id_idx');
        }

        Schema::table('experiment_state_transitions', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
