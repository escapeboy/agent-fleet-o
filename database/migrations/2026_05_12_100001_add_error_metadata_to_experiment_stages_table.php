<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `error_metadata` JSONB column to experiment_stages.
 *
 * Persists the SentryEventCapturer payload (sentry_event_id, error_class,
 * error_message, captured_at, tags, fingerprint, trace) so the admin UI can
 * deep-link to Sentry without re-querying the SDK.
 *
 * Indexes a GIN expression on sentry_event_id for fast lookup from the
 * RecentErrorsWidget; the rest of the JSONB is searched ad-hoc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('telemetry');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS experiment_stages_sentry_event_id_idx '
                ."ON experiment_stages ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS experiment_stages_sentry_event_id_idx');
        }

        Schema::table('experiment_stages', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
