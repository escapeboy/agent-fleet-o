<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->jsonb('checkpoint_data')->nullable()->after('output');
            $table->timestamp('last_heartbeat_at')->nullable()->after('completed_at');
            $table->string('worker_id', 100)->nullable()->after('last_heartbeat_at');
            $table->string('idempotency_key', 255)->nullable()->after('worker_id');
            $table->unsignedSmallInteger('checkpoint_version')->default(1)->after('idempotency_key');

            $table->index('last_heartbeat_at', 'idx_playbook_steps_heartbeat');
            $table->index('idempotency_key', 'idx_playbook_steps_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropIndex('idx_playbook_steps_heartbeat');
            $table->dropIndex('idx_playbook_steps_idempotency');
            $table->dropColumn([
                'checkpoint_data',
                'last_heartbeat_at',
                'worker_id',
                'idempotency_key',
                'checkpoint_version',
            ]);
        });
    }
};
