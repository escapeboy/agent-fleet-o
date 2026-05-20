<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table): void {
            $table->jsonb('error_metadata')->nullable()->after('error_message');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS playbook_steps_sentry_event_id_idx '
                ."ON playbook_steps ((error_metadata->>'sentry_event_id')) "
                .'WHERE error_metadata IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS playbook_steps_sentry_event_id_idx');
        }

        Schema::table('playbook_steps', function (Blueprint $table): void {
            $table->dropColumn('error_metadata');
        });
    }
};
