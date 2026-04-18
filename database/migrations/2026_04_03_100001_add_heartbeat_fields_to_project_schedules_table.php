<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_schedules', function (Blueprint $table) {
            $table->boolean('heartbeat_enabled')->default(false);
            $table->unsignedSmallInteger('heartbeat_interval_minutes')->nullable();
            $table->unsignedInteger('heartbeat_budget_cap')->nullable();
            $table->jsonb('heartbeat_context_sources')->default('["signals","metrics","audit"]');
        });
    }

    public function down(): void
    {
        Schema::table('project_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'heartbeat_enabled',
                'heartbeat_interval_minutes',
                'heartbeat_budget_cap',
                'heartbeat_context_sources',
            ]);
        });
    }
};
