<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_actions', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('response');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS outbound_actions_sentry_event_id_idx '
                ."ON outbound_actions ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS outbound_actions_sentry_event_id_idx');
        }

        Schema::table('outbound_actions', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
